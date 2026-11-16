<?php

namespace Dashed\DashedEcommerceReseller\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedCore\Models\BackgroundTask;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Feeds\ResellerFeedWriter;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Uniek per profiel en vertraagd: een import van honderd producten levert
 * honderd synchronisaties op, en dat moet één nieuw bestand worden. Zolang
 * deze job wacht, worden nieuwe aanvragen voor hetzelfde profiel genegeerd.
 * ShouldBeUniqueUntilProcessing in plaats van ShouldBeUnique: het slot gaat
 * al open zodra de job begint te draaien, niet pas als hij klaar is. Een
 * wijziging die binnenkomt terwijl deze job nog aan het schrijven is, plant
 * zo alsnog een nieuwe run in plaats van stilzwijgend te verdwijnen.
 */
class GenerateResellerFeedsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TASK_TYPE = 'reseller-feeds';

    public const TASK_TYPE_MANUAL = 'reseller-feeds-manual';

    /**
     * De beheerder die op "Feeds vernieuwen" klikte, voor een eigen taak met
     * melding. Een gewone eigenschap en geen promoted parameter: een job die
     * nog van voor deze eigenschap op de wachtrij staat, krijgt zo bij het
     * uitpakken null in plaats van een niet-geïnitialiseerde eigenschap.
     */
    public ?int $userId = null;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $profileId, ?int $userId = null)
    {
        $this->userId = $userId;

        $this->onQueue(config('dashed-ecommerce-reseller.sync.queue', 'ecommerce'));
    }

    public static function dispatchFor(ResellerProfile $profile): void
    {
        static::dispatch($profile->id)->delay(now()->addSeconds((int) config('dashed-ecommerce-reseller.feeds.delay_seconds', 300)));
    }

    public function uniqueId(): string
    {
        return (string) $this->profileId;
    }

    public function uniqueFor(): int
    {
        return (int) config('dashed-ecommerce-reseller.feeds.delay_seconds', 300) + 600;
    }

    public function handle(ResellerFeedWriter $writer): void
    {
        $profile = ResellerProfile::query()->with(['user', 'assortment.rules'])->find($this->profileId);

        if ($profile === null || ! $profile->isActive()) {
            return;
        }

        $task = $this->task($profile);

        $writer->write($profile, fn () => $task?->advance());

        $task?->finish(
            __('De feeds zijn bijgewerkt.'),
            rescue(fn () => ResellerResource::getUrl('edit', ['record' => $profile]), null, report: false),
            __('Afnemer bekijken'),
        );
    }

    /**
     * Na de laatste poging: de taak mag niet op "Bezig" blijven staan.
     */
    public function failed(Throwable $e): void
    {
        if (! class_exists(BackgroundTask::class)) {
            return;
        }

        rescue(function () use ($e) {
            $task = $this->userId
                ? $this->manualTask()
                : BackgroundTask::query()
                    ->where('subject_type', self::TASK_TYPE)
                    ->where('subject_id', $this->dailyKey())
                    ->active()
                    ->first();

            $task?->fail($e);
        }, report: false);
    }

    /**
     * In het takenicoon van dashed-core. Vanaf "Feeds vernieuwen" een eigen
     * taak van de beheerder (een nieuwe poging pakt die weer op). Verder
     * worden de feeds van een afnemer na elke reeks productwijzigingen
     * opnieuw geschreven, dus één regel per afnemer per dag in plaats van een
     * nieuwe per ronde. Een stap voor de catalogus en een per formaat.
     */
    private function task(ResellerProfile $profile): ?BackgroundTask
    {
        if (! class_exists(BackgroundTask::class)) {
            return null;
        }

        $steps = 1 + count(ResellerProfile::FEED_FORMATS);

        return rescue(function () use ($profile, $steps) {
            $label = __('Feeds van afnemer bijwerken: :naam', ['naam' => $profile->webhookOwnerLabel()]);

            if ($this->userId) {
                $task = $this->manualTask();

                if ($task) {
                    return $task->progress(0, $steps);
                }

                $task = BackgroundTask::begin(self::TASK_TYPE_MANUAL, $label, $steps, $this->userId);

                // Per afnemer terug te vinden, voor een nieuwe poging en failed().
                if ($task->exists) {
                    $task->forceFill(['subject_type' => self::TASK_TYPE_MANUAL, 'subject_id' => (string) $this->profileId])->save();
                }

                return $task;
            }

            $task = BackgroundTask::query()->firstOrNew([
                'subject_type' => self::TASK_TYPE,
                'subject_id' => $this->dailyKey(),
            ]);

            $task->fill([
                'type' => self::TASK_TYPE,
                'label' => $label,
                'user_id' => null,
                'status' => BackgroundTask::STATUS_RUNNING,
                'done' => 0,
                'total' => $steps,
                'notify' => false,
                'message' => null,
                'started_at' => now(),
                'finished_at' => null,
            ])->save();

            return $task;
        }, null, report: false);
    }

    private function manualTask(): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('type', self::TASK_TYPE_MANUAL)
            ->where('user_id', $this->userId)
            ->where('subject_type', self::TASK_TYPE_MANUAL)
            ->where('subject_id', (string) $this->profileId)
            ->active()
            ->latest('id')
            ->first();
    }

    private function dailyKey(): string
    {
        return $this->profileId . ':' . now()->toDateString();
    }
}

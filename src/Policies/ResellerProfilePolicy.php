<?php

namespace Dashed\DashedEcommerceReseller\Policies;

use Dashed\DashedCore\Policies\BaseResourcePolicy;

class ResellerProfilePolicy extends BaseResourcePolicy
{
    protected function resourceName(): string
    {
        return 'ResellerProfile';
    }
}

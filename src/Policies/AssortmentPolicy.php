<?php

namespace Dashed\DashedEcommerceReseller\Policies;

use Dashed\DashedCore\Policies\BaseResourcePolicy;

class AssortmentPolicy extends BaseResourcePolicy
{
    protected function resourceName(): string
    {
        return 'Assortment';
    }
}

<?php

namespace notwonderful\Wata;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    private const PROVIDER_ID = 'Wata';
    private const PROVIDER_CLASS = 'notwonderful\\Wata:Wata';
    private const ADDON_ID = 'notwonderful/Wata';

    public function installStep1(): void
    {
        $this->db()->insert('xf_payment_provider', [
            'provider_id' => self::PROVIDER_ID,
            'provider_class' => self::PROVIDER_CLASS,
            'addon_id' => self::ADDON_ID
        ]);
    }

    public function uninstallStep1(): void
    {
        $db = $this->db();

        $db->delete('xf_payment_profile', "provider_id = ?", self::PROVIDER_ID);
        $db->delete('xf_payment_provider', "provider_id = ?", self::PROVIDER_ID);
    }
}

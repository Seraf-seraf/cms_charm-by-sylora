<?php

namespace CDEK\Models\Settings;

use CDEK\Contracts\ValidatableSettingsContract;
use Exception;
use RuntimeException;

class SettingsAuth extends ValidatableSettingsContract
{
    public string $authId = '';
    public string $authSecret = '';
    public string $apiKey = '';
    public string $mapLangCode = 'rus';
    public bool $authTestMode = false;

    /**
     * @throws Exception
     */
    final public function validate(): void
    {
        if ($this->authTestMode) {
            return;
        }

        if (empty($this->authId)) {
            throw new RuntimeException('cdek_error_auth_id_empty');
        }

        if (empty($this->authSecret) || preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', trim($this->authSecret)) === 1) {
            throw new RuntimeException('cdek_error_auth_secret_empty');
        }

        if (empty($this->apiKey)) {
            throw new RuntimeException('cdek_error_auth_secret_empty');
        }
    }
}

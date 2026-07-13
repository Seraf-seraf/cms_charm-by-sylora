<?php

namespace CDEK\Actions\Admin\Settings;

use CDEK\Config;
use CDEK\Exceptions\DecodeException;
use CDEK\Exceptions\HttpServerException;
use CDEK\Helpers\LogHelper;
use CDEK\RegistrySingleton;
use CDEK\SettingsSingleton;
use CDEK\Transport\CdekApi;
use Exception;

class SaveSettingsAction
{
    public function __invoke(): void
    {
        $registry = RegistrySingleton::getInstance();

        $redirectUrl = $registry->get('url')
                                ->link(
                                    'extension/shipping/' . Config::DELIVERY_NAME,
                                    'user_token=' . $registry->get('session')->data['user_token'],
                                    true,
                                );

        /** @var \Response $response */
        $response = $registry->get('response');

        if($_SERVER['REQUEST_METHOD'] !== 'POST'){
            $response->redirect($redirectUrl);
            return;
        }

        $registry->get('load')->model('setting/setting');

        $storedSettings = SettingsSingleton::getInstance();
        $post = $_POST;

        if (empty($post['cdek_official__authSecret'])) {
            $post['cdek_official__authSecret'] = $storedSettings->authSettings->authSecret;
        }

        $settings = SettingsSingleton::getInstance($post);

        /** @var \Session $session */
        $session = $registry->get('session');
        /** @var \Language $language */
        $language = $registry->get('language');

        try {
            if (!CdekApi::checkAuth()) {
                throw new HttpServerException([
                    'message' => 'CDEK authorization failed',
                    'code' => 0,
                ]);
            }

            $settings->validate();
            $settings->save();
            $session->data['success'] = $language->get('text_success');
        } catch (DecodeException | HttpServerException $exception) {
            LogHelper::write('Authorization failed: ' . $exception->getMessage());
            $session->data['error_warning'] = $language->get('cdek_error_auth_unconnected');
        } catch (Exception $exception) {
            LogHelper::write('Validation failed: ' .
                             $language->get($exception->getMessage()));
            $session->data['error_warning'] = $language->get($exception->getMessage());
        }

        $response->redirect($redirectUrl);
    }
}

<?php declare(strict_types=1);

namespace Plugin\FormToTelegram;

use App\Domain\AbstractPlugin;
use App\Domain\Service\Form\DataService as FormDataService;
use App\Domain\Service\Form\FormService;
use App\Domain\Service\Parameter\ParameterService;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class FormToTelegramPlugin extends AbstractPlugin
{
    const NAME = 'Form2TelegramPlugin';
    const TITLE = 'Form to Telegram';
    const DESCRIPTION = 'Plugin duplicates form messages as text in telegram bot';
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://getwebspace.org';
    const VERSION = '2.0.0';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $self = $this;

        $this->addSettingsField([
            'label' => 'Token',
            'description' => 'The key can be obtained from <a href="https://t.me/BotFather" target="_blank">@BotFather</a> after creating a new bot',
            'type' => 'text',
            'name' => 'token',
        ]);
        $this->addSettingsField([
            'label' => 'Substitutions',
            'description' => 'Used to replace field names with human-readable headers',
            'type' => 'textarea',
            'name' => 'replaces',
            'message' => 'Ex: email:E-Mail',
        ]);
        $this->addSettingsField([
            'label' => 'Saved Chat IDs',
            'type' => 'textarea',
            'name' => 'config',
            'message' => 'Divisor semicolon',
            'args' => [
                'value' => $this->parameter('Form2TelegramPlugin_config', ''),
            ],
        ]);

        // subscribe events
        $this
            ->subscribe('common:catalog:order:create', [$self, 'tg_send_order'])
            ->subscribe('common:form:create', [$self, 'tg_send_form']);
    }

    public final function tg_send_order($order)
    {
        $homepage = $this->parameter('common_homepage', '');
        $replaces = $this->parameter('Form2TelegramPlugin_replaces', '');

        if ($order) {
            $args = [
                'name' => $order->user ? $order->user->name() : $order->delivery['client'],
                'phone' => $order->user && $order->user->phone ? $order->user->phone : $order->phone,
                'email' => $order->user && $order->user->email ? $order->user->email : $order->email,
                'address' => $order->delivery['address'],
                'comment' => $order->comment,
                'price' => $order->totalSum(),
            ];

            $body = '';
            foreach ($args as $key => $value) {
                if ($key === 'recaptcha') continue;
                if ($key === 'payment') continue;

                $value = str_replace('_', ' ', (string) $value);

                $body .= "*{$key}*: {$value}\n";
            }

            if ($replaces) {
                foreach (explode(PHP_EOL, $replaces) as $str) {
                    $buf = explode(':', $str);

                    if (!empty($buf) && $buf[0] && $buf[1]) {
                        $body = str_replace('*' . $buf[0] . '*', '*' . trim($buf[1]) . '*', $body);
                    }
                }
            }

            $body .= "\n[CUP]({$homepage}cup/catalog/order/{$order->uuid}/edit)";

            foreach ($this->getChatIds() as $chatId) {
                $this->sendChatMessage($chatId, $body);
            }
        }
    }

    public final function tg_send_form($formData)
    {
        $homepage = $this->parameter('common_homepage', '');
        $replaces = $this->parameter('Form2TelegramPlugin_replaces', '');

        if ($formData) {
            $data = $formData['data'] ?? [];

            $body = '';
            foreach ($data as $key => $value) {
                if ($key === 'recaptcha') continue;

                $value = str_replace('_', ' ', (string) $value);

                $body .= "*{$key}*: {$value}\n";
            }

            if ($replaces) {
                foreach (explode(PHP_EOL, $replaces) as $str) {
                    $buf = explode(':', $str);

                    if (!empty($buf) && $buf[0] && $buf[1]) {
                        $body = str_replace('*' . $buf[0] . '*', '*' . trim($buf[1]) . '*', $body);
                    }
                }
            }

            //$body .= "\n[CUP]({$homepage}cup/form/{$form->uuid}/view/{$formData->uuid})";

            foreach ($this->getChatIds() as $chatId) {
                $this->sendChatMessage($chatId, $body);
            }
        }
    }

    protected function getChatIds()
    {
        $response = $this->api('getUpdates');
        $output = explode(';', $this->parameter('Form2TelegramPlugin_config', ''));

        if (!empty($response['ok']) && $response['ok'] === true) {
            foreach ($response['result'] as $row) {
                $output[] = $row['message']['chat']['id'] ?? -1;
            }
            $output = array_unique($output);
            $this->parameter_set('Form2TelegramPlugin_config', implode(';', $output));
        }

        return $output;
    }

    protected function sendChatMessage($chatId, $message = '')
    {
        return $this->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);
    }

    protected function api($endpoint, $params = [])
    {
        if (($token = $this->parameter('Form2TelegramPlugin_token', '')) !== '') {
            $url = "https://api.telegram.org/bot{$token}/{$endpoint}?" . http_build_query($params);

            $this->logger->info('TelegramBot: request', $params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            $response = curl_exec($ch);
            curl_close($ch);

            $this->logger->info('TelegramBot: response', ['response' => $response]);

            if ($response) {
                return json_decode($response, true);
            }
        }

        return null;
    }

    protected function parameter_set($key, $value): array
    {
        $parameterService = $this->container->get(ParameterService::class);

        if (($this->parameter($key)) !== null) {
            $parameterService->update($key, ['key' => $key, 'value' => $value]);
        } else {
            $parameterService->create(['key' => $key, 'value' => $value]);
        }

        return [$key => $value];
    }
}

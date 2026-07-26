<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Mail\AlertStateChangedMail;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertNotifier
{
    /**
     * Deliver a state-change to every enabled channel on the alert. Each channel
     * is isolated: one failing endpoint never blocks the others or the evaluator.
     */
    public function notify(Alert $alert, AlertEvent $event): void
    {
        foreach ($alert->channels()->where('enabled', true)->get() as $channel) {
            try {
                match ($channel->type) {
                    ChannelType::Email => $this->sendEmail($channel, $alert, $event),
                    ChannelType::Webhook => $this->sendWebhook($channel, $alert, $event),
                    ChannelType::Telegram => $this->sendTelegram($channel, $alert, $event),
                };

                $channel->recordSuccess();
            } catch (Throwable $exception) {
                $channel->recordFailure();
                Log::warning('Alert notification failed', [
                    'alert_id' => $alert->id,
                    'channel_id' => $channel->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Deep link to the logs page pre-filtered to the alert's scope.
     */
    public function deepLink(Alert $alert): string
    {
        $query = array_filter([
            'servers' => implode(',', $alert->config['servers'] ?? []),
            'sources' => implode(',', $alert->config['sources'] ?? []),
            'search' => $alert->config['search'] ?? '',
        ]);

        $url = route('logs.index', ['current_team' => $alert->team->slug]);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    protected function sendEmail(NotificationChannel $channel, Alert $alert, AlertEvent $event): void
    {
        Mail::to($channel->config['address'])
            ->send(new AlertStateChangedMail($alert, $event, $this->deepLink($alert)));
    }

    protected function sendWebhook(NotificationChannel $channel, Alert $alert, AlertEvent $event): void
    {
        Http::timeout(5)->post($channel->config['url'], [
            'alert' => ['id' => $alert->id, 'name' => $alert->name, 'type' => $alert->type->value],
            'state' => $event->state->value,
            'measured_value' => $event->measured_value,
            'threshold' => $alert->config['threshold'],
            'window_minutes' => $alert->config['window_minutes'],
            'servers' => $alert->config['servers'],
            'sources' => $alert->config['sources'],
            'search' => $alert->config['search'],
            'fired_at' => $event->created_at->toISOString(),
            'url' => $this->deepLink($alert),
        ])->throw();
    }

    protected function sendTelegram(NotificationChannel $channel, Alert $alert, AlertEvent $event): void
    {
        $token = config('services.telegram.bot_token');

        throw_if(blank($token), new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.'));

        $text = sprintf(
            "<b>%s</b> is now <b>%s</b>\n%d line(s) in the last %d minute(s)\n%s",
            e($alert->name),
            $event->state->value,
            $event->measured_value,
            $alert->config['window_minutes'],
            $this->deepLink($alert),
        );

        Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $channel->config['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
        ])->throw();
    }
}

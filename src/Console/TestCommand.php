<?php

declare(strict_types=1);

namespace Enigma\Console;

use DateTimeImmutable;
use Enigma\ChannelConfig;
use Enigma\GoogleChatMessage;
use Enigma\Support\Sender;
use Enigma\Support\WebhookUrl;
use Illuminate\Console\Command;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use UnhandledMatchError;
use ValueError;

/**
 * Sends a sample card to every webhook of a channel and explains the result.
 */
class TestCommand extends Command
{
    protected $signature = 'google-chat-log:test
        {--channel=google-chat : The log channel whose webhook urls are tested}
        {--level=error : The log level of the sample message}';

    protected $description = 'Send a test message to a Google Chat log channel and diagnose the response';

    public function handle(): int
    {
        $name = (string) $this->option('channel');

        if (! is_array(config("logging.channels.{$name}"))) {
            $this->components->error("Log channel [{$name}] is not defined in config/logging.php.");

            return self::FAILURE;
        }

        try {
            $level = Level::fromName((string) $this->option('level'));
        } catch (UnhandledMatchError|ValueError) {
            $this->components->error("Invalid log level [{$this->option('level')}].");

            return self::FAILURE;
        }

        $config = ChannelConfig::forChannel($this->configChannelName($name));
        $urls = $config->urls();

        if ($urls === []) {
            $this->components->error("No webhook url is configured for [{$name}]. Set LOG_GOOGLE_CHAT_WEBHOOK_URL or the channel's \"url\" key.");

            return self::FAILURE;
        }

        $this->warnAboutGates($config);

        try {
            $body = GoogleChatMessage::fromRecord($this->sampleRecord($name, $level), $config)->toArray();
        } catch (Throwable $exception) {
            $this->components->error('Building the message failed: '.WebhookUrl::scrub($exception->getMessage(), $urls));

            return self::FAILURE;
        }

        // Diagnose the first response, so retries are disabled here.
        $sender = new Sender($config->timeout(), $config->connectTimeout());
        $failed = false;

        foreach ($urls as $url) {
            $masked = WebhookUrl::mask($url);

            try {
                $status = $sender->send($url, $body)->status();
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->twoColumnDetail($masked, '<fg=red>CONNECTION FAILED</>');
                $this->line('  '.WebhookUrl::scrub($exception->getMessage(), $urls));

                continue;
            }

            [$ok, $diagnosis] = $this->diagnose($status);
            $failed = $failed || ! $ok;

            $this->components->twoColumnDetail($masked, $ok ? "<fg=green>{$status} OK</>" : "<fg=red>{$status}</>");
            $this->line("  {$diagnosis}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A human readable explanation of a webhook response status.
     *
     * @return array{0: bool, 1: string}
     */
    protected function diagnose(int $status): array
    {
        return match (true) {
            $status >= 200 && $status < 300 => [true, 'Delivered. Check the space for the test card.'],
            $status === 400 => [false, 'Google rejected the payload as invalid. Please report this with the package version.'],
            $status === 401 => [false, 'Unauthorized: the webhook key or token is wrong. Copy the url from the space again.'],
            $status === 403 => [false, 'Forbidden: incoming webhooks are probably disabled by your Workspace admin (Admin console > Apps > Google Workspace > Google Chat > Chat apps > "Allow users to add and use incoming webhooks").'],
            $status === 404 => [false, 'Not found: the webhook or the space was deleted. Create a new webhook and update the url.'],
            $status === 429 => [false, 'Rate limited: the space allows about 1 request per second, shared by all its webhooks. Retry later, or enable retries.'],
            $status >= 500 => [false, 'Google Chat returned a server error. This is usually transient; enable retries to ride it out.'],
            default => [false, 'Unexpected response from Google Chat.'],
        };
    }

    /**
     * Warn when the channel's gates would block normal delivery.
     */
    protected function warnAboutGates(ChannelConfig $config): void
    {
        if (! $config->enabled()) {
            $this->components->warn('The channel is disabled (LOG_GOOGLE_CHAT_ENABLED=false); normal log messages are not sent. Sending the test anyway.');
        }

        if (! $config->allowsCurrentEnvironment()) {
            $this->components->warn(sprintf(
                'The current environment [%s] is not in the channel\'s "environments" list (%s); normal log messages are not sent. Sending the test anyway.',
                app()->environment(),
                implode(', ', (array) $config->environments()),
            ));
        }
    }

    /**
     * The channel whose config is read: a "with.channel" entry wins, matching
     * how the handler itself is constructed.
     */
    protected function configChannelName(string $name): string
    {
        $with = config("logging.channels.{$name}.with.channel");

        return is_string($with) && $with !== '' ? $with : $name;
    }

    protected function sampleRecord(string $channel, Level $level): LogRecord
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: $channel,
            level: $level,
            message: 'Test message from php artisan google-chat-log:test',
        );

        $record->formatted = (new LineFormatter(null, 'Y-m-d H:i:s', true, true, true))->format($record);

        return $record;
    }
}

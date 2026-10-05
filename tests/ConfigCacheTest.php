<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Level;

class ConfigCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.google-chat.additional_logs', ChatExtras::class);
    }

    public function test_config_with_a_class_string_hook_survives_config_caching(): void
    {
        // Exactly what "php artisan config:cache" does with the loaded config.
        $cached = $this->cacheRoundTrip(config()->all());

        $this->assertSame(ChatExtras::class, $cached['logging']['channels']['google-chat']['additional_logs']);
        $this->assertSame(config('logging.channels.google-chat'), $cached['logging']['channels']['google-chat']);
    }

    public function test_a_closure_hook_in_config_would_not_survive_config_caching(): void
    {
        // Why the per-channel hook is a class-string: closures can not be cached.
        $this->expectException(\Error::class);

        $this->cacheRoundTrip(['additional_logs' => fn () => []]);
    }

    public function test_the_class_string_hook_is_used(): void
    {
        Http::fake();

        $this->handle($this->makeRecord(Level::Error));

        Http::assertSent(function (Request $request) {
            $widgets = $request->data()['cardsV2'][0]['card']['sections'][0]['widgets'];

            return end($widgets)['decoratedText']['text'] === '<b>Level Name:</b> ERROR';
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function cacheRoundTrip(array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gcl-config');

        try {
            file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);

            return require $path;
        } finally {
            @unlink($path);
        }
    }
}

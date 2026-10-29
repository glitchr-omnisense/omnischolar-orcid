<?php

namespace Omnischolar\Orcid;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * ORCID's public API: identity, CV, declared works - no key.
 *
 *   options:
 *     base_uri: 'https://pub.orcid.org/v3.0/'   # the sandbox: https://pub.sandbox.orcid.org/v3.0/
 *     throttle: 0                           # seconds between two calls
 */
final class OrcidSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnischolar.factory_name' => 'orcid',
            'omnischolar.required_options' => [],
            'base_uri' => OrcidSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new OrcidSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', OrcidSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle', 0.0),
        );
    }
}

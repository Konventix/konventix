<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The per-brand storage form (ADR-0043 point 6, ADR-0009): the bucket a brand
 * brings of its own, shaped like the S3 disk it becomes.
 *
 * `useOwnStorage: false` clears the settings, which puts the brand back on
 * the deployment's media disk — it does not move any file. Everything already
 * uploaded keeps the disk recorded on its own row until `storage:migrate`
 * moves it, which is what makes switching a data operation rather than a
 * cutover.
 *
 * A null `secretAccessKey` on already-configured storage keeps the stored
 * secret: it is entered once and never echoed back.
 */
#[TypeScript]
class BrandStorageSettingsData extends Data
{
    public function __construct(
        public bool $useOwnStorage = false,
        public ?string $bucket = null,
        public ?string $region = null,
        public ?string $endpoint = null,
        public ?string $prefix = null,
        public bool $usePathStyleEndpoint = false,
        public bool $publicDirect = false,
        public ?string $url = null,
        public ?string $accessKeyId = null,
        public ?string $secretAccessKey = null,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'useOwnStorage' => ['boolean'],
            'bucket' => ['nullable', 'required_if:useOwnStorage,true', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:255'],
            'usePathStyleEndpoint' => ['boolean'],
            'publicDirect' => ['boolean'],
            // Where visitors fetch the bucket directly, the URL is what every
            // media link is built from, so it is required rather than optional
            // once that claim is made.
            'url' => ['nullable', 'required_if:publicDirect,true', 'url', 'max:255'],
            'accessKeyId' => ['nullable', 'required_if:useOwnStorage,true', 'string', 'max:255'],
            'secretAccessKey' => ['nullable', 'string', 'max:255'],
        ];
    }
}

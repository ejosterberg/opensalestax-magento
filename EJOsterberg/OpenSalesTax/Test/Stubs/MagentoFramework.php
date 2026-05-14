<?php
// SPDX-License-Identifier: Apache-2.0
/**
 * Minimal class/interface stubs for the Magento framework types our module
 * touches. Used at test time and by PHPStan so we do not need to install
 * Magento via the Marketplace composer repo (which requires authentication).
 *
 * In a merchant's Magento install the REAL Magento classes load first; these
 * stubs are guarded with class_exists/interface_exists so they no-op.
 *
 * These declarations live in `autoload-dev.files` of composer.json so they
 * are NOT bundled into the package merchants install.
 */
declare(strict_types=1);

namespace Magento\Framework {
    if (!class_exists(__NAMESPACE__ . '\\DataObject', false)) {
        /**
         * Minimal stub of \Magento\Framework\DataObject — enough for our tests
         * to construct one with an array and read back via getData($key).
         */
        class DataObject
        {
            /** @var array<string, mixed> */
            protected array $_data;

            /**
             * @param array<string, mixed> $data
             */
            public function __construct(array $data = [])
            {
                $this->_data = $data;
            }

            /**
             * @return mixed
             */
            public function getData(string $key = '')
            {
                if ($key === '') {
                    return $this->_data;
                }
                return $this->_data[$key] ?? null;
            }

            public function setData(string $key, mixed $value): self
            {
                $this->_data[$key] = $value;
                return $this;
            }
        }
    }
}

namespace Magento\Framework\Component {
    if (!class_exists(__NAMESPACE__ . '\\ComponentRegistrar', false)) {
        class ComponentRegistrar
        {
            public const MODULE = 'module';

            public static function register(string $type, string $componentName, string $path): void
            {
                // no-op stub
            }
        }
    }
}

namespace Magento\Framework\HTTP\Client {
    if (!class_exists(__NAMESPACE__ . '\\Curl', false)) {
        class Curl
        {
            /**
             * @param array<string, string> $headers
             */
            public function setHeaders(array $headers): void
            {
            }

            public function setTimeout(int $seconds): void
            {
            }

            /**
             * @param string|array<string, mixed> $body
             */
            public function post(string $url, $body): void
            {
            }

            public function get(string $url): void
            {
            }

            public function getStatus(): int
            {
                return 0;
            }

            public function getBody(): string
            {
                return '';
            }

            public function setOption(int $option, mixed $value): void
            {
            }
        }
    }
}

namespace Magento\Framework\Serialize\Serializer {
    if (!class_exists(__NAMESPACE__ . '\\Json', false)) {
        /**
         * Functional stub — actually serializes/deserializes JSON, so callers
         * relying on JSON-correct behavior get it.
         */
        class Json
        {
            /**
             * @param mixed $value
             */
            public function serialize($value): string
            {
                $encoded = json_encode($value);
                return $encoded === false ? '' : $encoded;
            }

            /**
             * @return mixed
             */
            public function unserialize(string $str)
            {
                return json_decode($str, true);
            }
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(__NAMESPACE__ . '\\ScopeConfigInterface', false)) {
        interface ScopeConfigInterface
        {
            /**
             * @return mixed
             */
            public function getValue(string $path, string $scopeType = 'default', ?string $scopeCode = null);

            public function isSetFlag(string $path, string $scopeType = 'default', ?string $scopeCode = null): bool;
        }
    }
}

namespace Magento\Framework\Encryption {
    if (!interface_exists(__NAMESPACE__ . '\\EncryptorInterface', false)) {
        interface EncryptorInterface
        {
            public function encrypt(string $data): string;

            public function decrypt(string $data): string;
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(__NAMESPACE__ . '\\ScopeInterface', false)) {
        interface ScopeInterface
        {
            public const SCOPE_STORE = 'store';
            public const SCOPE_STORES = 'stores';
            public const SCOPE_WEBSITE = 'website';
            public const SCOPE_WEBSITES = 'websites';
        }
    }
}

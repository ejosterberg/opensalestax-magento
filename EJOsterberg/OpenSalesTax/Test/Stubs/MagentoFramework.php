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

namespace Magento\Framework\App\Config\Storage {
    if (!interface_exists(__NAMESPACE__ . '\\WriterInterface', false)) {
        /**
         * Stub of Magento's config writer. Real implementation persists to
         * `core_config_data`; our backend_model uses save() to pin the resolved
         * IP and delete() to clear it.
         */
        interface WriterInterface
        {
            public function save(string $path, string $value, string $scope = 'default', int $scopeId = 0): void;

            public function delete(string $path, string $scope = 'default', int $scopeId = 0): void;
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!class_exists(__NAMESPACE__ . '\\Value', false)) {
        /**
         * Minimal stub of Magento's config backend-model base. The real class
         * extends `Magento\Framework\Model\AbstractModel` which pulls in heavy
         * Magento internals (registry, cache type list, resource model, etc.);
         * we only need enough surface for backend_model unit tests:
         *   - getValue / setValue
         *   - getFieldsetDataValue(name) — for reading sibling fields from the
         *     same admin form submission
         *   - beforeSave() — the lifecycle hook we override
         */
        class Value
        {
            /** @var array<string, mixed> */
            private array $_data = [];

            /**
             * Accept any constructor args so subclasses can declare the real
             * Magento DI signature without us reproducing it.
             */
            public function __construct(...$args)
            {
            }

            /**
             * @return mixed
             */
            public function getValue()
            {
                return $this->_data['value'] ?? null;
            }

            public function setValue(mixed $value): self
            {
                $this->_data['value'] = $value;
                return $this;
            }

            /**
             * @return mixed
             */
            public function getFieldsetDataValue(string $name)
            {
                return $this->_data['fieldset_data'][$name] ?? null;
            }

            public function setFieldsetDataValue(string $name, mixed $value): self
            {
                $this->_data['fieldset_data'][$name] = $value;
                return $this;
            }

            public function beforeSave(): self
            {
                return $this;
            }

            public function afterSave(): self
            {
                return $this;
            }

            public function getScope(): string
            {
                return (string)($this->_data['scope'] ?? 'default');
            }

            public function getScopeId(): int
            {
                return (int)($this->_data['scope_id'] ?? 0);
            }

            public function setScope(string $scope): self
            {
                $this->_data['scope'] = $scope;
                return $this;
            }

            public function setScopeId(int $scopeId): self
            {
                $this->_data['scope_id'] = $scopeId;
                return $this;
            }
        }
    }
}

namespace Magento\Framework\Model {
    if (!class_exists(__NAMESPACE__ . '\\Context', false)) {
        /**
         * Stub of Magento's model `Context`. The real class wires event
         * dispatcher + app state + action validator, but for our backend-
         * model unit tests we only need the type to exist.
         */
        class Context
        {
        }
    }
}

namespace Magento\Framework\Model\ResourceModel {
    if (!class_exists(__NAMESPACE__ . '\\AbstractResource', false)) {
        /**
         * Stub of Magento's resource-model base. Backend models accept this
         * as an optional ctor arg; we never invoke any methods on it from
         * unit tests.
         */
        class AbstractResource
        {
        }
    }
}

namespace Magento\Framework\Data\Collection {
    if (!class_exists(__NAMESPACE__ . '\\AbstractDb', false)) {
        /**
         * Stub of Magento's DB-collection base. Same role as AbstractResource
         * above — type-only for backend-model ctor signatures.
         */
        class AbstractDb
        {
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(__NAMESPACE__ . '\\Registry', false)) {
        /**
         * Stub of Magento's `Registry` (deprecated upstream but still
         * required by `Magento\Framework\Model\AbstractModel::__construct`).
         */
        class Registry
        {
        }
    }
}

namespace Magento\Framework\App\Cache {
    if (!interface_exists(__NAMESPACE__ . '\\TypeListInterface', false)) {
        /**
         * Stub of Magento's cache-type-list interface. Backend models
         * receive this so they can invalidate caches after save; we never
         * exercise that path in unit tests.
         */
        interface TypeListInterface
        {
            public function invalidate(string|array $typeCode): void;
        }
    }
}

namespace Magento\Framework\Exception {
    if (!class_exists(__NAMESPACE__ . '\\LocalizedException', false)) {
        /**
         * Minimal stub of Magento's localizable exception. Real implementation
         * accepts a Phrase; we accept any stringable for the test surface.
         */
        class LocalizedException extends \Exception
        {
            /**
             * @param string|object $phrase Either a plain string or a Phrase-like object.
             */
            public function __construct($phrase, ?\Throwable $cause = null, int $code = 0)
            {
                parent::__construct((string)$phrase, $code, $cause);
            }
        }
    }
}

namespace {
    if (!function_exists('__')) {
        /**
         * Magento's translation helper. Declared in the global namespace by
         * Magento at runtime via `app/functions.php`. In production it returns
         * a Phrase instance; for tests we return the formatted string and rely
         * on the stubbed LocalizedException accepting `string|object`.
         */
        function __(string $text, ...$args): string
        {
            if ($args === []) {
                return $text;
            }
            return vsprintf($text, $args);
        }
    }
}

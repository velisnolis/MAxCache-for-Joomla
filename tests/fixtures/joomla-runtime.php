<?php

declare(strict_types=1);

// Joomla API doubles only. The tests exercise the real plugin and filesystem helpers
// through their public event handlers, without booting a site or contacting a DB.
namespace Joomla\Registry {
    class Registry
    {
        private array $values;

        public function __construct(array|string $values = [])
        {
            $this->values = \is_array($values) ? $values : (json_decode($values, true) ?: []);
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }
    }
}

namespace Joomla\Event {
    interface SubscriberInterface {}

    final class Priority
    {
        public const MIN = -3;
        public const NORMAL = 0;
    }
    interface DispatcherAwareInterface {}

    class Event
    {
        public function __construct(public string $name, private array $arguments = []) {}

        public function getArgument(string $name, mixed $default = null): mixed
        {
            return $this->arguments[$name] ?? $default;
        }

        public function setArgument(string $name, mixed $value): static
        {
            $this->arguments[$name] = $value;

            return $this;
        }
    }

    trait DispatcherAwareTrait
    {
        private object $dispatcher;

        public function setDispatcher(object $dispatcher): void
        {
            $this->dispatcher = $dispatcher;
        }

        public function getDispatcher(): object
        {
            return $this->dispatcher;
        }
    }
}

namespace Joomla\CMS\Event\Application {
    class AfterRouteEvent extends \Joomla\Event\Event {}
    class AfterRenderEvent extends \Joomla\Event\Event {}
    class BeforeRespondEvent extends \Joomla\Event\Event {}
    class AfterRespondEvent extends \Joomla\Event\Event {}
}

namespace Joomla\CMS\Event\PageCache {
    class GetKeyEvent extends \Joomla\Event\Event {}
    class IsExcludedEvent extends \Joomla\Event\Event {}
}

namespace Joomla\CMS\Plugin {
    class CMSPlugin
    {
        protected \Joomla\Registry\Registry $params;
        private object $application;

        public function __construct(array $config)
        {
            $this->params = new \Joomla\Registry\Registry($config['params'] ?? []);
        }

        public function setApplication(object $application): void
        {
            $this->application = $application;
        }

        public function getApplication(): object
        {
            return $this->application;
        }
    }

    class PluginHelper
    {
        public static function importPlugin(...$arguments): bool
        {
            return true;
        }

        /** @var string[] Enabled plugins as "type/element". */
        public static array $enabled = [];

        public static function isEnabled(string $type, ?string $plugin = null): bool
        {
            return \in_array($type . '/' . $plugin, self::$enabled, true);
        }
    }
}

namespace Joomla\CMS {
    class Factory
    {
        public static object $application;
        public static int $cacheCleanCalls = 0;

        public static function getApplication(): object
        {
            return self::$application;
        }

        public static function getConfig(): \Joomla\Registry\Registry
        {
            return new \Joomla\Registry\Registry(['secret' => 'fixture-only-not-a-secret']);
        }

        public static function getContainer(): never
        {
            throw new \LogicException('Database services are intentionally unavailable in this fixture.');
        }

        public static function getCache(...$arguments): object
        {
            return new class {
                public function clean(): bool
                {
                    ++Factory::$cacheCleanCalls;
                    return true;
                }
            };
        }
    }
}

namespace Joomla\CMS\Session {
    class Session
    {
        public static bool $validToken = true;

        public static function checkToken(string $method): bool
        {
            return self::$validToken;
        }

        public static function getFormToken(): string
        {
            return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        }
    }
}

namespace Joomla\CMS\Language {
    class Text
    {
        public static function _(string $key): string
        {
            return $key;
        }
    }
}

namespace Joomla\CMS\Router {
    class Route
    {
        public static function _(string $url, bool $xhtml = true): string
        {
            return $url;
        }
    }
}

namespace Joomla\CMS\Date {
    class Date extends \DateTimeImmutable {}
}

namespace Joomla\CMS\Uri {
    class Uri
    {
        public static string $request = '/news';

        public static function getInstance(): self
        {
            return new self();
        }

        public function getQuery(bool $asArray = false): array|string
        {
            $query = (string) parse_url(self::$request, PHP_URL_QUERY);
            parse_str($query, $values);
            return $asArray ? $values : $query;
        }

        public function getPath(): string
        {
            return (string) parse_url(self::$request, PHP_URL_PATH);
        }

        public function getHost(): string { return 'example.test'; }
        public function getScheme(): string { return 'https'; }
        public function toString(): string { return 'https://example.test' . self::$request; }
        public static function root(bool $pathOnly = false): string { return $pathOnly ? '' : 'https://example.test/'; }
    }
}

namespace MaxcacheTests {
    class InputBag
    {
        public function __construct(private array $values = []) {}
        public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        public function getString(string $key, string $default = ''): string { return (string) $this->get($key, $default); }
    }

    class Input
    {
        public InputBag $server;
        public InputBag $cookie;

        public function __construct(private array $values = [], string $request = '/news')
        {
            $this->server = new InputBag(['HTTP_HOST' => 'example.test', 'REQUEST_URI' => $request]);
            $this->cookie = new InputBag();
        }

        public function getCmd(string $key, string $default = ''): string { return (string) ($this->values[$key] ?? $default); }
        public function getInt(string $key, int $default = 0): int { return (int) ($this->values[$key] ?? $default); }
        public function getMethod(): string { return (string) ($this->values['method'] ?? 'GET'); }
    }

    class Identity
    {
        public function __construct(public bool $guest = true, private array $grants = []) {}

        public function authorise(string $action, ?string $asset = null): bool
        {
            return !$this->guest && (\in_array('core.admin', $this->grants, true)
                || \in_array($action . ($asset !== null ? ':' . $asset : ''), $this->grants, true));
        }
    }

    class ApplicationClosed extends \RuntimeException {}

    class Application
    {
        public array $headers = [];
        public array $sentHeaders = [];
        public array $userState = [];
        public array $messages = [];
        public string $body = '<html><body>public page</body></html>';
        public int $statusCode = 200;
        public int $lateHeaderChanges = 0;
        private bool $sent = false;
        private bool $cacheable = false;

        public function __construct(public string $client, public Input $input, public Identity $identity) {}
        public function isClient(string $client): bool { return $this->client === $client; }
        public function getInput(): Input { return $this->input; }
        public function getIdentity(): Identity { return $this->identity; }
        public function getBody(): string { return $this->body; }
        public function setBody(string $body): void { $this->body = $body; }
        public function getHeaders(): array { return $this->headers; }
        public function getMessageQueue(): array { return $this->messages; }
        public function set(string $key, mixed $value): void {}
        public function getUserState(string $key): mixed { return $this->userState[$key] ?? null; }
        public function setUserState(string $key, mixed $value): void { $this->userState[$key] = $value; }
        public function enqueueMessage(string $message, string $type): void { $this->messages[] = [$message, $type]; }
        public function redirect(string $url): void {}
        public function close(): never { throw new ApplicationClosed(); }

        public function getResponse(): object
        {
            return new class($this->statusCode) {
                public function __construct(private int $status) {}
                public function getStatusCode(): int { return $this->status; }
            };
        }

        public function getMenu(): object
        {
            return new class { public function getActive(): mixed { return null; } };
        }

        public function getSession(): object
        {
            return new class { public function getName(): string { return 'fixture-session'; } };
        }

        public function allowCache(?bool $allow = null): bool
        {
            if ($allow !== null) { $this->cacheable = $allow; }
            return $this->cacheable;
        }

        public function setHeader(string $name, string $value, bool $replace = false): void
        {
            if ($this->sent) { ++$this->lateHeaderChanges; }
            if ($replace) {
                $this->headers = array_values(array_filter($this->headers,
                    static fn (array $header): bool => strcasecmp($header['name'], $name) !== 0));
            }
            $this->headers[] = ['name' => $name, 'value' => $value];
        }

        public function respond(): void
        {
            // Match Joomla's ordering: generic browser no-store is added inside
            // respond(), then headers are sent, then onAfterRespond is dispatched.
            if (!$this->allowCache()) {
                $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', false);
            }
            $this->setHeader('Status', (string) $this->statusCode, true);
            $this->sentHeaders = $this->headers;
            $this->sent = true;
        }
    }
}

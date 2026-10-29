<?php

// The parts of fof/seo and pusher/pusher-php-server (flarum/realtime) that Moments uses, for PHPStan only.

namespace FoF\Seo {
    class SeoProperties
    {
        public function setTitle(string $title, bool $updatePageTitle = true, bool $useAsHeadLine = false): self
        {
            return $this;
        }

        public function setDescription(?string $content = null): self
        {
            return $this;
        }

        public function setUrl(string $url, bool $prependApplicationUrl = true): self
        {
            return $this;
        }

        public function setImage(?string $imageUrl): self
        {
            return $this;
        }

        public function setPublishedOn(string $datetime): self
        {
            return $this;
        }
    }
}

namespace FoF\Seo\Page {
    use FoF\Seo\SeoProperties;
    use Psr\Http\Message\ServerRequestInterface;

    interface PageDriverInterface
    {
        /** @return array<int, string> */
        public function extensionDependencies(): array;

        /** @return array<int, string> */
        public function handleRoutes(): array;

        public function handle(ServerRequestInterface $request, SeoProperties $seo): void;
    }
}

namespace FoF\Seo\Extend {
    use Flarum\Extend\ExtenderInterface;
    use Flarum\Extension\Extension;
    use Illuminate\Contracts\Container\Container;

    class SEO implements ExtenderInterface
    {
        /** @param class-string $extender */
        public function addExtender(string $name, string $extender): self
        {
            return $this;
        }

        public function extend(Container $container, ?Extension $extension = null): void
        {
        }
    }
}

namespace Pusher {
    class Pusher
    {
        /**
         * @param string|array<int, string> $channels
         * @param array<string, mixed> $data
         */
        public function trigger(string|array $channels, string $event, array $data): object
        {
            return new \stdClass();
        }
    }
}

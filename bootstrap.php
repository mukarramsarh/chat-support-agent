<?php

declare(strict_types=1);

/**
 * Application bootstrap — the single composition root. Everything is wired here
 * so business classes never reach for globals. Returns a fully configured
 * Container. Both the web front controller and the CLI console use this.
 */

use SupportAI\Application\Chat\ChatService;
use SupportAI\Application\Chat\ContextRetriever;
use SupportAI\Application\Chat\NullRetriever;
use SupportAI\Http\Controller\AdminController;
use SupportAI\Http\Controller\ChatController;
use SupportAI\Http\Controller\WidgetController;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Infrastructure\LLM\Pricing;
use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\AdminUserRepository;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\ConversationRepository;
use SupportAI\Infrastructure\Persistence\MessageRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Config;
use SupportAI\Support\Container;
use SupportAI\Support\Crypto;
use SupportAI\Support\Env;
use SupportAI\Support\Http\HttpClient;
use SupportAI\Support\Logger;

require __DIR__ . '/vendor/autoload.php';

Env::load(__DIR__ . '/.env');

$config = Config::fromEnv();
date_default_timezone_set($config->string('app.timezone', 'UTC'));

$c = new Container();
$c->instance(Config::class, $config);

$c->set(Logger::class, fn () => new Logger(__DIR__ . '/storage/logs/app.log'));
$c->set(HttpClient::class, fn () => new HttpClient(120));
$c->set(Crypto::class, fn (Container $c) => new Crypto($c->get(Config::class)->string('app.key', 'insecure-dev-key-change-me')));
$c->set(Pricing::class, fn () => new Pricing());

$c->set(Database::class, fn (Container $c) => new Database($c->get(Config::class)));

// ── Repositories ──
$c->set(SettingsRepository::class, fn (Container $c) => new SettingsRepository($c->get(Database::class)));
$c->set(AgentRepository::class, fn (Container $c) => new AgentRepository($c->get(Database::class)));
$c->set(ConversationRepository::class, fn (Container $c) => new ConversationRepository($c->get(Database::class)));
$c->set(MessageRepository::class, fn (Container $c) => new MessageRepository($c->get(Database::class)));
$c->set(AdminUserRepository::class, fn (Container $c) => new AdminUserRepository($c->get(Database::class)));
$c->set(UsageRepository::class, fn (Container $c) => new UsageRepository($c->get(Database::class), $c->get(Pricing::class)));

// ── Providers & vector store ──
$c->set(ProviderFactory::class, fn (Container $c) => new ProviderFactory($c->get(Config::class), $c->get(HttpClient::class)));
$c->set(VectorStoreFactory::class, fn (Container $c) => new VectorStoreFactory(
    $c->get(Config::class), $c->get(Database::class), $c->get(HttpClient::class), $c->get(Logger::class)
));

// ── Retrieval seam: NullRetriever now, RagRetriever in Phase 2 ──
$c->set(ContextRetriever::class, fn () => new NullRetriever());

// ── Application services ──
$c->set(ChatService::class, fn (Container $c) => new ChatService(
    $c->get(ProviderFactory::class),
    $c->get(ContextRetriever::class),
    $c->get(ConversationRepository::class),
    $c->get(MessageRepository::class),
    $c->get(UsageRepository::class),
    $c->get(Config::class),
    $c->get(Logger::class),
));

// ── Controllers ──
$c->set(ChatController::class, fn (Container $c) => new ChatController(
    $c->get(AgentRepository::class),
    $c->get(ConversationRepository::class),
    $c->get(ChatService::class),
    $c->get(Config::class),
));
$c->set(WidgetController::class, fn (Container $c) => new WidgetController(
    $c->get(AgentRepository::class),
    $c->get(Config::class),
));
$c->set(AdminController::class, fn (Container $c) => new AdminController(
    $c->get(AdminUserRepository::class),
    $c->get(AgentRepository::class),
    $c->get(UsageRepository::class),
    $c->get(VectorStoreFactory::class),
    $c->get(Database::class),
    $c->get(Config::class),
));

return $c;

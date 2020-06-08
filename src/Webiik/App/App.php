<?php
declare(strict_types=1);

namespace Webiik\Framework;

use Webiik\Container\Container;
use Webiik\Cookie\Cookie;
use Webiik\Csrf\Csrf;
use Webiik\Data\Data;
use Webiik\Middleware\Middleware;
use Webiik\Router\Router;
use Webiik\Session\Session;
use Webiik\Token\Token;

class App
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var Middleware
     */
    private $middleware;

    /**
     * App constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        // Instantiate Container
        $this->container = new Container();

        // Instantiate Middleware
        $this->middleware = new Middleware($this->container, new Data());

        // Add service wsConfig and instantiate it
        $this->container->addService('wsConfig', function () {
            return new Data();
        });
        $wsConfig = $this->container->get('wsConfig');

        // Load the 'app' config file to configArr
        if (file_exists(WEBIIK_BASE_DIR . '/config/app.local.php')) {
            $configArr = require_once WEBIIK_BASE_DIR . '/config/app.local.php';
        } else {
            $configArr = require_once WEBIIK_BASE_DIR . '/config/app.php';
        }

        // Sanitize baseUri in configArr
        $configArr['app']['baseUri'] = '/' . trim($configArr['app']['baseUri'], '/');

        // Define WEBIIK_DEBUG for user convenience
        define('WEBIIK_DEBUG', $configArr['app']['mode'] == 'development');

        // Define WEBIIK_BASE_URI for user convenience
        define('WEBIIK_BASE_URI', $configArr['app']['baseUri']);

        // Add configArr values to wsConfig
        $wsConfig->set('app', $configArr['app']);

        // Get language from URI (if language is not supported use default language)
        preg_match('~^/([a-z]{2})/~i', $this->getStrippedUri(), $lang);
        $lang = isset($lang[1]) ? $lang[1] : '';
        $lang = isset($configArr['app']['languages'][$lang]) ? $configArr['app']['languages'][$lang] : $this->getDefaultLanguage($configArr['app']['defaultLanguage']);
        define('WEBIIK_LANG', $lang);

        // Set encoding by language
        mb_internal_encoding($configArr['app']['languages'][$lang][1]);

        // Set timezone by language
        date_default_timezone_set($configArr['app']['languages'][$lang][0]);

        // Load the 'resources' config file to configArr
        $configArr = $this->loadConfig('resources', WEBIIK_BASE_DIR . '/config', true);

        // Add configArr values to wsConfig
        foreach ($configArr as $key => $val) {
            $wsConfig->set($key, $val);
        }

        // Add service Cookie
        $this->container->addService('Webiik\Cookie\Cookie', function ($c) {
            $cookieConfig = $c->get('wsConfig')->get('services')['Cookie'];
            $cookie = new Cookie();
            $cookie->setDomain($cookieConfig['domain']);
            $cookie->setUri($cookieConfig['uri']);
            $cookie->setSecure($cookieConfig['secure']);
            $cookie->setHttpOnly($cookieConfig['httpOnly']);
            return $cookie;
        });

        // Add service Session
        $this->container->addService('Webiik\Session\Session', function ($c) {
            $sessionConfig = $c->get('wsConfig')->get('services')['Session'];
            $cookieConfig = $c->get('wsConfig')->get('services')['Cookie'];
            $session = new Session();
            $session->setSessionName($sessionConfig['name']);
            $session->setSessionDir($sessionConfig['dir']);
            $session->setSessionGcProbability($sessionConfig['gcProbability']);
            $session->setSessionGcLifetime($sessionConfig['gcLifetime']);
            $session->setSessionGcDivisor($sessionConfig['gcDivisor']);
            $session->setDomain($cookieConfig['domain']);
            $session->setUri($cookieConfig['uri']);
            $session->setSecure($cookieConfig['secure']);
            $session->setHttpOnly($cookieConfig['httpOnly']);
            return $session;
        });

        // Add service Token
        $this->container->addService('Webiik\Token\Token', function () {
            return new Token();
        });

        // Add service Csrf
        $this->container->addService('Webiik\Csrf\Csrf', function (Container $c) {
            $csrfConfig = $c->get('wsConfig')->get('services')['Csrf'];
            $csrf = new Csrf($this->container->get('Webiik\Token\Token'),
                $this->container->get('Webiik\Session\Session'));
            $csrf->setName($csrfConfig['name']);
            $csrf->setMax($csrfConfig['max']);
            return $csrf;
        });

        // Add service Router
        $this->container->addService('Webiik\Router\Router', function (Container $c) {
            $router = new Router();
            $router->setDefaultLang(WEBIIK_LANG);
            $router->setDefaultLangInURI($c->get('wsConfig')->get('services')['Router']['defaultLangInURI']);
            $router->setBaseURI(WEBIIK_BASE_URI);

            // Define WEBIIK_BASE_URL for user convenience
            define('WEBIIK_BASE_URL', $router->getBaseURL());

            // Define WEBIIK_BASE_PATH for user convenience
            // Note: Same as the URL but always has trailing slash
            define('WEBIIK_BASE_PATH', rtrim(WEBIIK_BASE_URL, '/') . '/');

            return $router;
        });

        // Load services from config/container/services.{WEBIIK_LANG}.php
        $load = $this->loadConfig('services', WEBIIK_BASE_DIR . '/config/container/');
        foreach ($load as $service => $factory) {
            $this->container->addService($service, $factory);
        }

        // Load models from config/container/models.{WEBIIK_LANG}.php
        $load = $this->loadConfig('models', WEBIIK_BASE_DIR . '/config/container/');
        foreach ($load as $service => $factory) {
            $this->container->addService($service, $factory);
        }

        // Load middleware from config/middleware/middleware.{WEBIIK_LANG}.php
        $load = $this->loadConfig('middleware', WEBIIK_BASE_DIR . '/config/middleware/');
        foreach ($load as $middleware => $data) {
            $this->middleware->add($middleware, $data);
        }

        // Instantiate Webiik Error
        if ($this->container->isIn('Webiik\Error\Error')) {
            $this->container->get('Webiik\Error\Error');
        }
    }

    /**
     * Run Webiik application
     * @throws \ReflectionException
     */
    public function run(): void
    {
        // Load shared or lang specific routes
        $router = $this->container->get('Webiik\Router\Router');
        /** @var Router $router */
        $routes = $this->loadConfig('routes', WEBIIK_BASE_DIR . '/config/routes');

        foreach ($routes as $routeName => $route) {
            $newRoute = $router->addRoute($route['methods'], '/' . trim($route['uri'], '/'),
                '\Webiik\Controller\\' . $route['controller'], $routeName);
            foreach ($route['mw'] as $controller => $data) {
                $newRoute->mw($controller, $data);
            }
        }

        // Find route by URL
        $route = $router->match();
        $routeController = 'Webiik\Controller\P404:run';
        if ($router->getHttpCode() == 200) {
            $routeController = $route->getController();
            $routeController = $routeController[0] . ':' . $routeController[1];

        } elseif ($router->getHttpCode() == 405) {
            $routeController = 'Webiik\Controller\P405:run';

        }

        // Add Route object to Container
        $this->container->addParam('Webiik\Router\Route', $route);

        // (mw) Add route middleware to Middleware
        foreach ($route->getMw() as $mw) {
            $this->middleware->add($mw['controller'], $mw['data']);
        }

        // (mw) Add route controller to Middleware
        $this->middleware->add($routeController);

        // Run middleware
        $this->middleware->run();
    }

    /**
     * Load and return content of configuration file
     * Prefer lang specific configuration file
     *
     * @param string $name
     * @param string $dir
     * @param bool $local
     * @return array
     */
    private function loadConfig(string $name, string $dir, bool $local = false): array
    {
        if ($local) {
            if (file_exists($dir . '/' . $name . '.' . WEBIIK_LANG . '.local.php')) {
                return require_once $dir . '/' . $name . '.' . WEBIIK_LANG . '.local.php';
            }

            if (file_exists($dir . '/' . $name . '.local.php')) {
                return require_once $dir . '/' . $name . '.local.php';
            }
        }

        if (file_exists($dir . '/' . $name . '.' . WEBIIK_LANG . '.php')) {
            return require_once $dir . '/' . $name . '.' . WEBIIK_LANG . '.php';

        } else {
            return require_once $dir . '/' . $name . '.php';
        }
    }

    /**
     * Return current uri stripped from WEBIIK_BASE_URI defined in configuration
     * @return mixed
     */
    private function getStrippedUri()
    {
        return str_replace(WEBIIK_BASE_URI, '', $_SERVER['REQUEST_URI']);
    }

    /**
     * Return default language according to main configuration file, eventually to current hostname
     * @param $defaultLanguageConf
     * @return string
     * @throws \Exception
     */
    private function getDefaultLanguage($defaultLanguageConf): string
    {
        // If default language is configured by string, it's a default language
        if (is_string($defaultLanguageConf)) {
            return $defaultLanguageConf;
        }

        // If default language is configured by array, then default language
        // is language its host regex matches current host. If there is no match,
        // , then first language in array is used as default.
        foreach ($defaultLanguageConf as $host => $lang) {
            if (preg_match($host, $_SERVER['SERVER_NAME'])) {
                return $lang;
            }
        }
        foreach ($defaultLanguageConf as $host => $lang) {
            return $lang;
        }

        throw new \Exception('Default language is not set.');
    }
}

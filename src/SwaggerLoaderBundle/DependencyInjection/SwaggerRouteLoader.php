<?php

namespace Electrotiti\SwaggerLoaderBundle\DependencyInjection;

use Electrotiti\SwaggerTools\SwaggerParser;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\Yaml\Yaml;


class SwaggerRouteLoader extends Loader
{
    private $loaded = false;
    private $config;

    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * Load route based on Swagger definition
     * @param mixed $resource
     * @param null $type
     * @return RouteCollection
     */
    public function load($resource, $type = null)
    {

        if (true === $this->loaded) {
            throw new \RuntimeException('Do not add the Swagger loader twice');
        }
        $routes = new RouteCollection();

        $parser = new SwaggerParser();
        $apiSpec = $parser->parse($this->config['definition']);


        $routing = [];
        $yamlParser = new Yaml();
        foreach ($this->config['routing'] as $routingFile) {
            $data = $yamlParser->parse(file_get_contents($routingFile));
            $routing = array_merge($routing, $data);
        }

        foreach ($apiSpec['paths'] as $path => $methods) {

            foreach ($methods as $method => $def) {
                if (false === array_key_exists('operationId', $def)) {
                    throw new \RuntimeException('OperationId missing in Swagger.json for path : '.$path);
                }

                $operationId = $def['operationId'];

                if (false === array_key_exists($operationId, $routing)) {
                    throw new \RuntimeException('Mapping between "'.$operationId.'" and his controller missing in routing.yml');
                }

                $controller = $routing[$operationId]['controller'];

                // Scopes
                if (!isset($def['security'])) {
                    $scopes = [ 'user' ];
                } else {
                    foreach ($def['security'] as $types) {
                        if (isset($types['token'])) {
                            $scopes = $types['token'];
                        } elseif (isset($types['apps'])) {
                            $scopes = $types['apps'];
                        } else {
                            throw new \Exception('Unknown security for '.$operationId);
                        }
                    }
                }

                // Parameters for route
                $defaults = array(
                    '_controller'       => $controller,
                    '_scopes'           => $scopes,
                    '_api_auth'         => true,
                    '_api_operation_id' => $operationId
                );

                $bodyParameterName = $this->getBodyParameterName($def);
                if (null !== $bodyParameterName) {
                    $defaults['_api_parameter_in_body'] = $bodyParameterName;
                }

                if (isset($def['x-no-token'])) {
                    $defaults['_api_no_token'] = true;
                }

                // Create the route
                $route = new Route($path);
                $route->setMethods($method);

                if (isset($def['x-igraal-ttl'])) {
                    if ([ Request::METHOD_GET ] !== $route->getMethods()) {
                        throw new \InvalidArgumentException('Unable to set cache on non-get method');
                    }

                    $value = intval($def['x-igraal-ttl']);
                    if (0 === $value) {
                        throw new \InvalidArgumentException('Invalid ttl set for operation '.$operationId);
                    }
                    $defaults['_igraal_cache_ttl'] = $value;
                    $defaults['_igraal_cache_parameters'] = $this->getParametersNames($def);
                }

                $route->addDefaults($defaults);
                $this->addRequirements($route, $def);

                // Add the new route to the route collection
                $routeName = 'api_swagger_'.$operationId;
                $routes->add($routeName, $route);

                // Return 200 on OPTIONS request for swagger editor... not working for the moment...
                $routeOptions = new Route(
                    $path,
                    ['_controller' => 'IgraalApiApiBundle:Definition/Definition:acceptOptionsRequest',
                        '_scopes' => $scopes,
                        '_api_auth_options' => true]
                );


                $routeOptions->setMethods('OPTIONS');
                $this->addRequirements($routeOptions, $def);
                $routes->add($routeName . '_options', $routeOptions);
            }
        }

        $this->loaded = true;
        return $routes;
    }

    /**
     * This loader support "swagger" type
     * @param mixed $resource
     * @param null $type
     * @return bool
     */
    public function supports($resource, $type = null)
    {
        return 'swagger' === $type;
    }

    /**
     * Add route requirements
     *
     * @param Route $route
     * @param array $def
     *
     * @return void
     */
    private function addRequirements(Route $route, $def)
    {
        if (!isset($def['parameters'])) {
            return;
        }

        $parameters = array_filter(
            $def['parameters'],
            function ($parameterDef) {
                return isset($parameterDef['in']) && 'path' === $parameterDef['in'];
            }
        );

        foreach ($parameters as $parameter) {
            $type = $parameter['type'];
            $name = $parameter['name'];
            if ($type === 'integer') {
                $route->addRequirements([ $name => '\d+']);
            }
        }
    }

    /**
     * Retrieve body parameter name in definition
     *
     * @param string $definition
     *
     * @return null
     */
    private function getBodyParameterName($definition)
    {
        if (!isset($definition['parameters'])) {
            return null;
        }

        $bodyParameters = array_filter(
            $definition['parameters'],
            function ($parameterConfig) {
                return $parameterConfig['in'] === 'body';
            }
        );
        $bodyParameters = array_values($bodyParameters);

        switch (count($bodyParameters)) {
            case 0:
                return null;
            case 1:
                return $bodyParameters[0]['name'];

            default:
                throw new \LogicException('Bad definition : only one body parameter is allowed');
        }
    }

    private function getParametersNames($definition)
    {
        if (!isset($definition['parameters'])) {
            return [];
        }

        return array_map(
            function (array $parameter) {
                return $parameter['name'];
            },
            $definition['parameters']
        );
    }
}

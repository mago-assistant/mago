<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Laminas\Http\Headers;
use Laminas\Stdlib\Parameters;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\RequestFactory;
use Magento\Webapi\Controller\Rest\InputParamsResolverFactory;
use Magento\Webapi\Controller\Rest\Router;
use Magento\Webapi\Controller\Rest\RouterFactory;

/**
 * Matches a call against the store's webapi.xml routes the way the REST front controller does, on
 * a request of its own: the request Magento shares holds the admin page (or the outer REST call)
 * that is running the chat, and its query, post data and headers must not leak into the call.
 */
class RouteResolver
{
    private const CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly RouterFactory $routerFactory,
        private readonly InputParamsResolverFactory $inputParamsResolverFactory,
        private readonly Json $json
    ) {
    }

    /**
     * @throws WebapiException When no route matches, as REST answers with a 404
     */
    public function resolve(ApiCall $call): ResolvedRoute
    {
        $request = $this->createRequest($call);
        $router = $this->routerFactory->create();
        $inputParamsResolver = $this->inputParamsResolverFactory->create([
            'request' => $request,
            'router' => $router,
        ]);
        $route = $inputParamsResolver->getRoute();

        return new ResolvedRoute(
            (string)$route->getServiceClass(),
            (string)$route->getServiceMethod(),
            (string)$route->getRoutePath(),
            array_map('strval', (array)$route->getAclResources()),
            (array)$inputParamsResolver->getInputData(),
            $route->getInputArraySizeLimit()
        );
    }

    private function createRequest(ApiCall $call): Request
    {
        $request = $this->requestFactory->create();
        $request->setHeaders(Headers::fromString('Content-Type: ' . self::CONTENT_TYPE));
        $request->setQuery(new Parameters($this->normalizeQuery($call->query)));
        $request->setPost(new Parameters([]));
        $request->setMethod($call->httpMethod);
        $request->setPathInfo($call->getPath());
        $request->setContent($call->hasBody() ? (string)$this->json->serialize($call->body) : '');

        return $request;
    }

    /**
     * buildSearchCriteria() hands back flat keys like "searchCriteria[pageSize]"; PHP only turns those
     * into the nested array Magento reads once they went through a query string.
     *
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    private function normalizeQuery(array $query): array
    {
        $normalized = [];
        parse_str(http_build_query($query), $normalized);

        return $normalized;
    }
}

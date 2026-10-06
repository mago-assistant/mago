<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Framework\Webapi\Validator\EntityArrayValidator\InputArraySizeLimitValue;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Api\InProcess\FollowUp\ServiceCallFollowUpInterface;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ServiceCallGuardInterface;

/**
 * Runs a web API route in this PHP process for one admin user, doing what the REST front controller
 * does for a synchronous request: store from the URL, route match, ACL, input conversion, the service
 * call and output conversion. The ObjectManager resolves the service class the route names, exactly as
 * Magento\Webapi\Controller\Rest\SynchronousRequestProcessor does.
 */
class ServiceDispatcher
{
    /**
     * @param ServiceCallGuardInterface[] $guards
     * @param ServiceCallFollowUpInterface[] $followUps
     */
    public function __construct(
        private readonly StoreEmulation $storeEmulation,
        private readonly AdminAuthorizationFactory $authorizationFactory,
        private readonly RouteResolver $routeResolver,
        private readonly RouteAuthorizer $routeAuthorizer,
        private readonly ServiceInputProcessor $serviceInputProcessor,
        private readonly InputArraySizeLimitValue $inputArraySizeLimitValue,
        private readonly ServiceOutputConverter $serviceOutputConverter,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ErrorMapper $errorMapper,
        private readonly TransactionBoundary $transactionBoundary,
        private readonly ErrorReporter $errorReporter,
        private readonly array $guards = [],
        private readonly array $followUps = []
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function dispatch(ApiCall $call): array
    {
        try {
            return $this->storeEmulation->run(
                $call,
                fn (): array => $this->transactionBoundary->run(fn (): array => $this->execute($call))
            );
        } catch (\Throwable $throwable) {
            return $this->errorMapper->toError($throwable);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function execute(ApiCall $call): array
    {
        $authorization = $this->authorizationFactory->create($call->adminUserId);
        $route = $this->routeResolver->resolve($call);
        $this->routeAuthorizer->assertAllowed($route, $authorization);
        $this->runGuards($route, $authorization);

        $arguments = $this->getArguments($route);
        $output = $this->objectManager->get($route->serviceClass)->{$route->serviceMethod}(...$arguments);
        $this->runFollowUps($route, $arguments);

        return $this->serviceOutputConverter->convert($output, $route, $authorization);
    }

    /**
     * @return array<int, mixed>
     */
    private function getArguments(ResolvedRoute $route): array
    {
        $this->inputArraySizeLimitValue->set($route->inputArraySizeLimit);

        return $this->serviceInputProcessor->process($route->serviceClass, $route->serviceMethod, $route->inputData);
    }

    private function runGuards(ResolvedRoute $route, AuthorizationInterface $authorization): void
    {
        foreach ($this->guards as $guard) {
            $guard->guard($route, $authorization);
        }
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function runFollowUps(ResolvedRoute $route, array $arguments): void
    {
        foreach ($this->followUps as $followUp) {
            // The service call already succeeded; a failed follow-up must not report it as failed.
            try {
                $followUp->afterCall($route, $arguments);
            } catch (\Throwable $e) {
                $this->errorReporter->log('Internal API follow-up ' . $route->routePath, $e);
            }
        }
    }
}

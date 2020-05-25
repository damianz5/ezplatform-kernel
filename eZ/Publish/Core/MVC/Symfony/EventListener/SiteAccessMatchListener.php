<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Publish\Core\MVC\Symfony\EventListener;

use Doctrine\ORM\EntityManager;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\MVC\Symfony\Component\Serializer\SerializerTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use eZ\Publish\Core\MVC\Symfony\SiteAccess;
use eZ\Publish\Core\MVC\Symfony\SiteAccess\Router as SiteAccessRouter;
use eZ\Publish\Core\MVC\Symfony\Event\PostSiteAccessMatchEvent;
use eZ\Publish\Core\MVC\Symfony\MVCEvents;
use eZ\Publish\Core\MVC\Symfony\Routing\SimplifiedRequest;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * kernel.request listener, triggers SiteAccess matching.
 * Should be triggered as early as possible.
 */
class SiteAccessMatchListener implements EventSubscriberInterface
{
    use SerializerTrait;

    /** @var \eZ\Publish\Core\MVC\Symfony\SiteAccess\Router */
    protected $siteAccessRouter;

    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface */
    protected $eventDispatcher;

    /** @var \Doctrine\DBAL\Connection */
    private $connection;

    public function __construct(
        SiteAccessRouter $siteAccessRouter,
        EventDispatcherInterface $eventDispatcher,
        EntityManager $entityManager
    ) {
        $this->siteAccessRouter = $siteAccessRouter;
        $this->eventDispatcher = $eventDispatcher;
        $this->connection = $entityManager->getConnection();
    }

    public static function getSubscribedEvents()
    {
        return [
            // Should take place just after FragmentListener (priority 48) in order to get rebuilt request attributes in case of subrequest
            KernelEvents::REQUEST => ['onKernelRequest', 45],
        ];
    }

    /**
     * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
     */
    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();

        // We have a serialized siteaccess object from a fragment (sub-request), we need to get it back.
        if ($request->attributes->has('serialized_siteaccess')) {
            $serializer = $this->getSerializer();
            /** @var SiteAccess $siteAccess */
            $siteAccess = $serializer->deserialize($request->attributes->get('serialized_siteaccess'), SiteAccess::class, 'json');
            if ($siteAccess->matcher !== null) {
                if (in_array(SiteAccess\Matcher::class, class_implements($siteAccess->matcher))) {

                    $siteAccess->matcher = $serializer->deserialize(
                        $request->attributes->get('serialized_siteaccess_matcher'),
                        $siteAccess->matcher,
                        'json',
                        [
                            AbstractNormalizer::IGNORED_ATTRIBUTES => ['request', 'connection'],
                            'default_constructor_arguments' => [
                                $siteAccess->matcher => [
                                    'connection' => $this->connection
                                ]
                            ],
                        ]
                    );

                } else {
                    throw new InvalidArgumentException(
                        'matcher',
                        sprintf(
                            'SiteAccess matcher must implement %s or %s',
                            SiteAccess\Matcher::class,
                            SiteAccess\URILexer::class
                        )
                    );
                }
            }

            $request->attributes->set(
                'siteaccess',
                $siteAccess
            );
            $request->attributes->remove('serialized_siteaccess');
        } elseif (!$request->attributes->has('siteaccess')) {
            // Get SiteAccess from original request if present ("_ez_original_request" attribute), or current request otherwise.
            // "_ez_original_request" attribute is present in the case of user context hash generation (aka "user hash request").
            $request->attributes->set(
                'siteaccess',
                $this->getSiteAccessFromRequest($request->attributes->get('_ez_original_request', $request))
            );
        }

        $siteaccess = $request->attributes->get('siteaccess');
        if ($siteaccess instanceof SiteAccess) {
            $siteAccessEvent = new PostSiteAccessMatchEvent($siteaccess, $request, $event->getRequestType());
            $this->eventDispatcher->dispatch($siteAccessEvent, MVCEvents::SITEACCESS);
        }
    }

    /**
     * @param Request $request
     *
     * @return SiteAccess
     */
    private function getSiteAccessFromRequest(Request $request)
    {
        return $this->siteAccessRouter->match(
            new SimplifiedRequest(
                [
                    'scheme' => $request->getScheme(),
                    'host' => $request->getHost(),
                    'port' => $request->getPort(),
                    'pathinfo' => $request->getPathInfo(),
                    'queryParams' => $request->query->all(),
                    'languages' => $request->getLanguages(),
                    'headers' => $request->headers->all(),
                ]
            )
        );
    }
}

<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Bundle\EzPublishCoreBundle\Fragment;

use eZ\Publish\Core\MVC\Symfony\Component\Serializer\SerializerTrait;
use eZ\Publish\Core\MVC\Symfony\SiteAccess\SiteAccessAware;
use eZ\Publish\Core\MVC\Symfony\SiteAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ControllerReference;
use Symfony\Component\HttpKernel\Fragment\FragmentRendererInterface;
use Symfony\Component\HttpKernel\Fragment\InlineFragmentRenderer as BaseRenderer;
use Symfony\Component\HttpKernel\Fragment\RoutableFragmentRenderer;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

class InlineFragmentRenderer extends BaseRenderer implements SiteAccessAware
{
    use SerializerTrait;

    /** @var \Symfony\Component\HttpKernel\Fragment\FragmentRendererInterface */
    private $innerRenderer;

    /** @var \eZ\Publish\Core\MVC\Symfony\SiteAccess */
    private $siteAccess;

    public function __construct(FragmentRendererInterface $innerRenderer)
    {
        $this->innerRenderer = $innerRenderer;
    }

    public function setFragmentPath($path)
    {
        if ($this->innerRenderer instanceof RoutableFragmentRenderer) {
            $this->innerRenderer->setFragmentPath($path);
        }
    }

    public function setSiteAccess(SiteAccess $siteAccess = null)
    {
        $this->siteAccess = $siteAccess;
    }

    public function render($uri, Request $request, array $options = [])
    {
        if ($uri instanceof ControllerReference) {
            if ($request->attributes->has('siteaccess')) {
                /** @var \eZ\Publish\Core\MVC\Symfony\SiteAccess $siteAccess */
                $siteAccess = $request->attributes->get('siteaccess');
                $uri->attributes['serialized_siteaccess'] = json_encode($siteAccess);
                $uri->attributes['serialized_siteaccess_matcher'] = $this->getSerializer()->serialize(
                    $siteAccess->matcher,
                    'json',
                    [AbstractNormalizer::IGNORED_ATTRIBUTES => ['request', 'connection']]
                );
            }
            if ($request->attributes->has('semanticPathinfo')) {
                $uri->attributes['semanticPathinfo'] = $request->attributes->get('semanticPathinfo');
            }
            if ($request->attributes->has('viewParametersString')) {
                $uri->attributes['viewParametersString'] = $request->attributes->get('viewParametersString');
            }
        }

        return $this->innerRenderer->render($uri, $request, $options);
    }

    public function getName()
    {
        return $this->innerRenderer->getName();
    }
}

<?php
declare(strict_types=1);

namespace Webiik\Framework;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    /**
     * @var TemplateHelpers
     */
    private $templateHelpers;

    /**
     * WebiikTwig constructor.
     * @param TemplateHelpers $helpers
     */
    public function __construct(TemplateHelpers $helpers)
    {
        $this->templateHelpers = $helpers;
    }

    /**
     * Return array with user defined Twig functions
     * @return array|TwigFunction[]|\Twig_Function[]
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('reactComponent', $this->templateHelpers->reactComponent(), [
                'pre_escape' => 'html',
                'is_safe' => ['html'],
            ]),
            new TwigFunction('getJS', $this->templateHelpers->getJs(), [
                'pre_escape' => 'html',
                'is_safe' => ['html'],
            ]),
            new TwigFunction('getCSS', $this->templateHelpers->getCss(), [
                'pre_escape' => 'html',
                'is_safe' => ['html'],
            ]),
            new TwigFunction('getRoute', $this->templateHelpers->getRoute()),
            new TwigFunction('getURL', $this->templateHelpers->getURL()),
            new TwigFunction('_t', $this->templateHelpers->_t()),
        ];
    }
}
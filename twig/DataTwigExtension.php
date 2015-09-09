<?php
namespace Grav\Plugin;

use \Grav\Common\Grav;

class DataTwigExtension extends \Twig_Extension
{
    protected $grav;

    public function __construct()
    {
        $this->grav = Grav::instance();
    }

    /**
     * Returns extension name.
     *
     * @return string
     */
    public function getName()
    {
        return 'DataTwigExtension';
    }

    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('cast_to_array', [$this, 'castToArrayFilter']),
        ];
    }

    public function castToArrayFilter($itemToCast)
    {
        $response = [];

        if (is_object($itemToCast)) {
            foreach ($itemToCast as $key => $value) {
                $response[$key] = $value;
            }
            return $response;
        }

        if (is_array($itemToCast)) {
            return $itemToCast;
        }

        return $itemToCast;
    }
}

<?php

function konzilo_twig($path, $reset=false) {
  static $environments = array();
  if (!empty($environments[$path]) && !$reset) {
    return $environments[$path];
  }
  $loader = new Twig_Loader_Filesystem($path);
  $twig = new Twig_Environment($loader, array(
    'cache' => '/tmp',
  ));
  $twig->addExtension(new KonziloWordpressTwig());
  $environments[$path] = $twig;
  return $environments[$path];
}

class KonziloWordpressTwig extends Twig_Extension {
  public function getName() {
    return 'KonziloWordpress';
  }

  public function getFilters() {
    return array(
      new Twig_SimpleFilter('t', function ($data) {
        return __($data, 'konzilo');
      })
    );
  }

  public function getFunctions() {
    return array(
      new Twig_SimpleFunction('wp_dropdown_categories', function ($args) {
        wp_dropdown_categories($args);
      }),
      new Twig_SimpleFunction('wp_nonce_field', function ($context, $name) {
        wp_nonce_field($context, $name);
      }),
      new Twig_SimpleFunction('settings_fields', function ($name) {
        settings_fields($name);
      }),
      new Twig_SimpleFunction('do_settings_sections', function ($name) {
        do_settings_sections($name);
      }),
      new Twig_SimpleFunction('__', function ($str) {
        return __($str, 'konzilo');
      }),

      new Twig_SimpleFunction('submit_button', function () {
        submit_button();
      })

    );
  }
}

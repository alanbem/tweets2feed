<?php

/*
 * This file is part of the Tweets2Feed application.
 *
 * Copyright (c) Alan Gabriel Bem <alan.bem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Constraints;
use Symfony\Component\Validator as Validator;

$app = new Silex\Application(require __DIR__ . '/config.php');

$app->register(new Silex\Provider\UrlGeneratorServiceProvider);
$app->register(new Silex\Provider\FormServiceProvider);
$app->register(new Silex\Provider\ValidatorServiceProvider);
$app->register(new Silex\Provider\SessionServiceProvider);
$app->register(new MarcW\Silex\Provider\BuzzServiceProvider);
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'locale_fallback' => 'en',
));
$app->register(new Silex\Provider\TwigServiceProvider, array(
    'twig.path' => array(
        __DIR__ . '/../templates', // custom templates
        __DIR__ . '/templates',
    ),
));
$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addFilter('linkify', new \Twig_Filter_Function(function (array $tweet) {
        $text = $tweet['text'];

        // hastags
        if (true === array_key_exists('hashtags', $tweet['entities'])) {
            $linkified = array();
            foreach ($tweet['entities']['hashtags'] as $hashtag) {
                $hash = $hashtag['text'];

                if (in_array($hash, $linkified)) {
                    continue; // do not process same hash twice or more
                }
                $linkified[] = $hash;

                // replace single words only, so looking for #Google we wont linkify >#Google<Reader
                $text = preg_replace('/#\b' . $hash . '\b/', sprintf('<a href="https://twitter.com/search?q=%%23%2$s&src=hash">#%1$s</a>', $hash, urlencode($hash)), $text);
            }
        }

        // user mentions
        if (true === array_key_exists('user_mentions', $tweet['entities'])) {
            $linkified = array();
            foreach ($tweet['entities']['user_mentions'] as $userMention) {
                $name = $userMention['name'];
                $screenName = $userMention['screen_name'];

                if (in_array($screenName, $linkified)) {
                    continue; // do not process same user mention twice or more
                }
                $linkified[] = $screenName;

                // replace single words only, so looking for @John we wont linkify >@John<Snow
                $text = preg_replace('/@\b' . $screenName . '\b/', sprintf('<a href="https://www.twitter.com/%1$s" title="%2$s">@%1$s</a>', $screenName, $name), $text);
            }
        }

        // urls
        if (true === array_key_exists('urls', $tweet['entities'])) {
            $linkified = array();
            foreach ($tweet['entities']['urls'] as $url) {
                $expandedUrl = $url['expanded_url'];
                $url = $url['url'];

                if (in_array($url, $linkified)) {
                    continue; // do not process same url twice or more
                }
                $linkified[] = $url;

                $text = str_replace($url, sprintf('<a href="%1$s" title="%2$s">%1$s</a>', $url, $expandedUrl), $text);
            }
        }

        // media
        if (true === array_key_exists('media', $tweet['entities'])) {
            $linkified = array();
            foreach ($tweet['entities']['media'] as $media) {
                $mediaUrl = $media['media_url'];
                $media = $media['url'];

                if (in_array($media, $linkified)) {
                    continue; // do not process same url twice or more
                }
                $linkified[] = $media;

                $text = str_replace($media, sprintf('<a href="%1$s" title="%2$s">%1$s</a>', $media, $mediaUrl), $text);
            }
        }

        return $text;
    }));

    $twig->addFilter('photos', new \Twig_Filter_Function(function (array $tweet) {
        $photos = array();

        if (false === array_key_exists('media', $tweet['entities'])) {
            return $photos;
        }

        foreach ($tweet['entities']['media'] as $media) {
            if ('photo' === $media['type']) {
                $photos[] = $media['media_url'];
            }
        }

        return $photos;
    }));

    return $twig;
}));
$app->register(new Silex\Provider\HttpCacheServiceProvider(), array(
    'http_cache.cache_dir' => __DIR__ . '/../cache',
    'http_cache.esi' => null, // as we do not use ESI, lets disable it
    'http_cache.options' => array(
        'default_ttl' => $app['cache.default_ttl'],
    ),
));
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__ . '/../logs/' . date('Ymd') . '.log', // rotating log file
));

$app['twitter.oauth.client'] = $app->share(function ($app) {

    $oauth = new OAuth($app['twitter.api.key'], $app['twitter.api.secret'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
    $oauth->setToken($app['twitter.oauth.token'], $app['twitter.oauth.token_secret']);

    return $oauth;
});

$app['form.constraint.twitter_username'] = $app->share(function ($app) {

    /* @var $buzz Buzz\Browser */
    $buzz = $app['buzz'];

    return new Constraints\Callback(array('methods' => array(function ($username, Validator\ExecutionContextInterface $context) use ($buzz) {

        if (0 === preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) {
            $context->addViolationAt('username', 'User with this screen name does not exist', array(), null);
            return ;
        }
        
        // simplest way to check if twitter user exist without API calls is just request regular user timeline
        $url = 'https://twitter.com/' . urldecode($username);

        try {
            $response = $buzz->get($url);

            /* @var $response Buzz\Message\Response */
            if ($response->getStatusCode() !== 200) {
                $context->addViolationAt('username', 'User with this screen name does not exist', array(), null);
            }
        } catch (Buzz\Exception\ClientException $e) {
            $context->addViolationAt('username', 'User with this screen name does not exist', array(), null);
        }

    })));
});

$app['form'] = $app->share(function ($app) {

    /* @var $builder \Symfony\Component\Form\FormBuilder */
    $builder = $app['form.factory']->createBuilder('form', array());
    $builder
        ->setAction($app['url_generator']->generate('search'))
        ->setMethod('POST')
        ->add('username', 'text', array(
            'constraints' => array(
                new Constraints\NotBlank(),
                $app['form.constraint.twitter_username'],
            ),
        ))
    ;

    return $builder->getForm();
});

$app['signer'] = $app->share(function ($app) {

    /**
     * Signs $params
     *
     * @param array $params
     * @return string
     */
    return function ($params) use ($app) {

        if (null === $app['secret']) {
            return $params;
        }

        $hash = hash_init('sha1');

        ksort($params);

        foreach ($params as $name => $param) {
            hash_update($hash, $name);
            hash_update($hash, $param);
        }

        hash_update($hash, $app['secret']);

        $params['signature'] = hash_final($hash);

        return $params;
    };
});

/**
 * Authorization middleware generator.
 *
 * @param array $names  Names of parameter which should be extracted from $request to generate authorisation signature.
 */
$authorization = function (array $names) use ($app) {

    if (true === empty($names)) {
        $message = 'At least one parameter name is required to authenticate $request';
        throw new \InvalidArgumentException($message);
    }

    /**
     * Url signature verifier.
     *
     * @param Request $request
     */
    return function (Request $request) use ($app, $names) {
        if (false === $request->query->has('signature')) {
            $app->abort(403, 'Wrong signature. Access Denied.'); // 403 Forbidden
        };

        $parameters = array();
        foreach ($names as $name) {
            if (false === $request->attributes->has($name)) {
                $app->abort(403, 'Missing authorisation parameter. Access Denied.'); // 403 Forbidden
            }

            $parameters[$name] = $request->get($name);
        }
        $parameters = $app['signer']($parameters);

        if ($request->query->get('signature') != $parameters['signature']) {
            $app->abort(403, 'Wrong signature. Access Denied.'); // 403 Forbidden
        }
    };
};

$caching = function (Request $request, Response $response) use ($app) {

    // disable caching in development environment
    if (true === $app['debug']) {
        return ;
    }

    if (500 === $response->getStatusCode()) {
        // Possibly Twitter API rate limit was exceeded so lets wait it out
        $response->setTtl(60 * 120);
    } else {
        // Currently duration of Twitters API rate limit window is 15 minutes,
        // 45 minutes cache is safety margin to not exceed that limit
        $response->setTtl(60 * 45);
    }
};

$app->get('/', function () use ($app) {

    $form = $app['form'];

    return $app['twig']->render('homepage.html.twig', array('form' => $form->createView()));
})
    ->bind('homepage')
;

$app->post('/', function (Request $request) use ($app) {

    /* @var $form \Symfony\Component\Form\Form */
    $form = $app['form'];

    $form->handleRequest($request);

    if ($form->isValid()) {
        $username = $form->get('username')->getData();

        return $app->redirect($app['url_generator']->generate('feed', $app['signer'](array('username' => $username))));
    }

    return $app['twig']->render('homepage.html.twig', array('form' => $form->createView()));
})
    ->bind('search')
;

$app->get('/{username}.atom', function ($username) use ($app) {

    /* @var $twitter OAuth */
    $twitter = $app['twitter.oauth.client'];

    try {

        $twitter->fetch('https://api.twitter.com/1.1/statuses/user_timeline.json', array('screen_name' => $username, 'count' => 200));

    } catch (\OAuthException $e) {
        $app->abort(500, $e->getMessage());
    }

    $tweets = json_decode($twitter->getLastResponse(), true);

    return $app['twig']->render('feed.atom.twig', array('username' => $username, 'tweets' => $tweets));
})
    ->before($authorization(array('username'))) // only username parameter is used to sign this request e.g. _format is excluded, even if provided
    ->bind('feed')
    ->value('_format', 'atom')
    ->assert('username', '[A-Za-z0-9_]{1,15}')
    ->after($caching)
;

return $app;

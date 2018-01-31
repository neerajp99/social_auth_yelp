<?php

namespace Drupal\social_auth_yelp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthDataHandler;
use Drupal\social_auth\SocialAuthUserManager;
use Drupal\social_auth_yelp\YelpAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Returns responses for Simple Yelp Connect module routes.
 */
class YelpAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * The yelp authentication manager.
   *
   * @var \Drupal\social_auth_yelp\YelpAuthManager
   */
  private $yelpManager;

  /**
   * Used to access GET parameters.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $request;

  /**
   * The Social Auth Data Handler.
   *
   * @var \Drupal\social_auth\SocialAuthDataHandler
   */
  private $dataHandler;

  /**
   * YelpAuthController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_yelp network plugin.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   * @param \Drupal\social_auth_yelp\YelpAuthManager $yelp_manager
   *   Used to manage authentication methods.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Used to access GET parameters.
   * @param \Drupal\social_auth\SocialAuthDataHandler $data_handler
   *   SocialAuthDataHandler object.
   */
  public function __construct(NetworkManager $network_manager,
                              SocialAuthUserManager $user_manager,
                              YelpAuthManager $yelp_manager,
                              RequestStack $request,
                              SocialAuthDataHandler $data_handler) {

    $this->networkManager = $network_manager;
    $this->userManager = $user_manager;
    $this->yelpManager = $yelp_manager;
    $this->request = $request;
    $this->dataHandler = $data_handler;

    // Sets the plugin id.
    $this->userManager->setPluginId('social_auth_yelp');

    // Sets the session keys to nullify if user could not logged in.
    $this->userManager->setSessionKeysToNullify(['access_token', 'oauth2state']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('social_auth.user_manager'),
      $container->get('social_auth_yelp.manager'),
      $container->get('request_stack'),
      $container->get('social_auth.data_handler')
    );
  }

  /**
   * Response for path 'user/login/yelp'.
   *
   * Redirects the user to Yelp for authentication.
   */
  public function redirectToYelp() {
    /* @var \Stevenmaguire\OAuth2\Client\Provider\Yelp false $yelp */
    $yelp = $this->networkManager->createInstance('social_auth_yelp')->getSdk();

    // If yelp client could not be obtained.
    if (!$yelp) {
      drupal_set_message($this->t('Social Auth Yelp not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Destination parameter specified in url.
    $destination = $this->request->getCurrentRequest()->get('destination');
    // If destination parameter is set, save it.
    if ($destination) {
      $this->userManager->setDestination($destination);
    }

    // Yelp service was returned, inject it to $yelpManager.
    $this->yelpManager->setClient($yelp);

    // Generates the URL where the user will be redirected for Yelp login.
    // If the user did not have email permission granted on previous attempt,
    // we use the re-request URL requesting only the email address.
    $yelp_login_url = $this->yelpManager->getYelpLoginUrl();

    $state = $this->yelpManager->getState();

    $this->dataHandler->set('oauth2state', $state);

    return new TrustedRedirectResponse($yelp_login_url);
  }

  /**
   * Response for path 'user/login/yelp/callback'.
   *
   * Yelp returns the user here after user has authenticated in yelp.
   */
  public function callback() {
    // Checks if user cancel login via Yelp.
    $error = $this->request->getCurrentRequest()->get('error');
    if ($error == 'access_denied') {
      drupal_set_message($this->t('You could not be authenticated.'), 'error');
      return $this->redirect('user.login');
    }

    /* @var \Stevenmaguire\OAuth2\Client\Provider\Yelp|false $yelp */
    $yelp = $this->networkManager->createInstance('social_auth_yelp')->getSdk();

    // If Yelp client could not be obtained.
    if (!$yelp) {
      drupal_set_message($this->t('Social Auth Yelp not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $state = $this->dataHandler->get('oauth2state');

    // Retrieves $_GET['state'].
    $retrievedState = $this->request->getCurrentRequest()->query->get('state');
    if (empty($retrievedState) || ($retrievedState !== $state)) {
      $this->userManager->nullifySessionKeys();
      drupal_set_message($this->t('Yelp login failed. Unvalid OAuth2 state.'), 'error');
      return $this->redirect('user.login');
    }

    // Saves access token to session.
    $this->dataHandler->set('access_token', $this->yelpManager->getAccessToken());

    $this->yelpManager->setClient($yelp)->authenticate();

    // Gets user's info from Yelp API.
    if (!$yelp_profile = $this->yelpManager->getUserInfo()) {
      drupal_set_message($this->t('Yelp login failed, could not load Yelp profile. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Store the data mapped with data points define is
    // social_auth_yelp settings.
    $data = [];

    if (!$this->userManager->checkIfUserExists($yelp_profile->getId())) {
      $api_calls = explode(PHP_EOL, $this->yelpManager->getApiCalls());

      // Iterate through api calls define in settings and try to retrieve them.
      foreach ($api_calls as $api_call) {

        $call = $this->yelpManager->getExtraDetails($api_call);
        array_push($data, $call);
      }
    }
    // If user information could be retrieved.
    return $this->userManager->authenticateUser($yelp_profile->getFirstName(), $yelp_profile->getEmail(), $yelp_profile->getId(), $this->yelpManager->getAccessToken(), json_encode($data));
  }

}

<?php

namespace Drupal\social_auth_yelp\Settings;

/**
 * Defines an interface for Social Auth Yelp settings.
 */
interface YelpAuthSettingsInterface {

  /**
   * Gets the client ID.
   *
   * @return string
   *   The client ID.
   */
  public function getClientId();

  /**
   * Gets the client secret.
   *
   * @return string
   *   The client secret.
   */
  public function getClientSecret();

  /**
   * Gets the restricted domain.
   *
   * @return string
   *   The restricted domain.
   */

}

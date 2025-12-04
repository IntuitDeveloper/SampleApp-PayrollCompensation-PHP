<?php
// Active environment can be switched via APP_ENV or by editing the line below
$activeEnvironment = getenv('APP_ENV') ?: 'production'; // 'sandbox' or 'production'

// Common (environment-agnostic) values
$authorizationRequestUrl = 'https://appcenter.intuit.com/connect/oauth2';
$tokenEndPointUrl = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
$oauthScope = 'com.intuit.quickbooks.accounting payroll.compensation.read';
$openIDScope = 'openid profile email';

// Define per-environment settings
$environments = array(
  'production' => array(
    'client_id' => 'client_id',
    'client_secret' => 'client_secret',
    'oauth_redirect_uri' => 'https://<ngrok-domain>/OAuth_2/OAuth2PHPExample.php', // eg: https://1a73fb02251b.ngrok-free.app/OAuth_2/OAuth2PHPExample.php
    'openID_redirect_uri' => 'https://<ngrok-domain>/OAuth_2/OAuthOpenIDExample.php',
    'mainPage' => 'https://<ngrok-domain>/OAuth_2/index.php',
    'refreshTokenPage' => 'https://<ngrok-domain>/OAuth_2/RefreshToken.php',
    'base_url' => 'https://quickbooks.api.intuit.com'
  ),
  'sandbox' => array(
    // TODO: Replace with your sandbox app credentials and URIs
    'client_id' => 'client_id',
    'client_secret' => 'client_secret',
    'oauth_redirect_uri' => 'https://<ngrok-domain>/OAuth_2/OAuth2PHPExample.php',
    'openID_redirect_uri' => 'https://<ngrok-domain>/OAuth_2/OAuthOpenIDExample.php',
    'mainPage' => 'https://<ngrok-domain>/OAuth_2/index.php',
    'refreshTokenPage' => 'https://<ngrok-domain>/OAuth_2/RefreshToken.php',
    'base_url' => 'https://sandbox-quickbooks.api.intuit.com'
  )
);

$active = isset($environments[$activeEnvironment]) ? $environments[$activeEnvironment] : $environments['sandbox'];

return array(
  // Environment: 'sandbox' or 'production'
  'environment' => $activeEnvironment,

  'authorizationRequestUrl' => $authorizationRequestUrl,
  'tokenEndPointUrl' => $tokenEndPointUrl,

  // Selected environment credentials and redirect URIs
  'client_id' => $active['client_id'],
  'client_secret' => $active['client_secret'],
  'oauth_scope' => $oauthScope,
  'openID_scope' => $openIDScope,
  'oauth_redirect_uri' => $active['oauth_redirect_uri'],
  'openID_redirect_uri' => $active['openID_redirect_uri'],
  'mainPage' => $active['mainPage'],
  'refreshTokenPage' => $active['refreshTokenPage'],
  'base_url' => $active['base_url'],
);
?>

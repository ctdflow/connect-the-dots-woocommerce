# Connect the Dots for WooCommerce

Plan the delivery routes of your WooCommerce orders with [Connect the Dots](https://ctdflow.com/woocommerce). Choose a delivery day, press Plan, and every van gets its orders in a route with an expected time for each stop and a link for the driver.

## Installing

Install it with Composer, for example in a [Bedrock](https://roots.io/bedrock/) site:

```bash
composer require ctdflow/connect-the-dots-woocommerce
```

The package has the type `wordpress-plugin`, so [composer/installers](https://github.com/composer/installers) places it in `wp-content/plugins/connect-the-dots`. Activate it, open WooCommerce > Delivery routes and connect your shop. The Dutch translation is built when a release is made, so a Composer install shows the plugin in English.

The plugin needs WordPress 6.5, WooCommerce 8.0 and PHP 8.1 or newer. What it sends to Connect the Dots, and when, is described under External services in `readme.txt`.

## Questions and issues

Report a problem or ask a question in the [issues](https://github.com/ctdflow/connect-the-dots-woocommerce/issues). The plugin is developed in the Connect the Dots repository and copied here on every change, so pull requests cannot be merged here directly. Describe the change in an issue and we take it over.

## Development

The plugin needs no build step. Composer is only used for `composer/installers` and the development tools in `vendor/`, which are never shipped (see `.distignore`).

## Running the tests

The tests run in [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), which needs Docker and Node. WordPress runs on port 8888 and the test site on port 8889. The plugin is mapped into WordPress as `wp-content/plugins/connect-the-dots`, next to WooCommerce from wordpress.org.

Install the development tools once:

```bash
composer install
```

Start WordPress (the first start downloads the Docker images and WooCommerce):

```bash
npx @wordpress/env@10 start
```

Run the tests:

```bash
npx @wordpress/env@10 run tests-cli --env-cwd=wp-content/plugins/connect-the-dots vendor/bin/phpunit
```

Pass PHPUnit options after the command, for example `--filter=PluginTest`.

Stop WordPress with `npx @wordpress/env@10 stop`, or remove everything it created with `npx @wordpress/env@10 destroy`.

## Other versions

wp-env reads `WP_ENV_CORE` and `WP_ENV_PHP_VERSION`. To test against the oldest versions the plugin supports, put this in `.wp-env.override.json` (git ignored) and run `npx @wordpress/env@10 start --update`:

```json
{
	"core": "https://wordpress.org/wordpress-6.4.zip",
	"phpVersion": "8.1",
	"plugins": [ "https://downloads.wordpress.org/plugin/woocommerce.8.0.0.zip" ]
}
```

## Other delivery date plugins

A plugin that stores delivery dates on orders can add itself with the `ctdflow_delivery_date_sources` filter. Return the sources with yours added, an object that implements `ConnectTheDots\DeliveryDates\DeliveryDateSource`. With the setting Detect automatically, the first source whose `isAvailable()` is true is used, so put yours first to win over the built in ones. Without an available source, orders are selected by status only.

```php
add_filter( 'ctdflow_delivery_date_sources', static function ( array $sources ): array {
	array_unshift( $sources, new My_Delivery_Date_Source() );

	return $sources;
} );
```

## Plugin Check

CI runs [Plugin Check](https://wordpress.org/plugins/plugin-check/) on the files that are shipped. To run it locally:

```bash
npx @wordpress/env@10 run cli wp plugin install plugin-check --activate
npx @wordpress/env@10 run cli wp plugin check connect-the-dots --exclude-directories=vendor,tests,.wordpress-org --exclude-files=.distignore,.gitignore,.wp-env.json,composer.json,composer.lock,phpunit.xml.dist,phpunit.multisite.xml.dist,README.md
```

## Translations

The Dutch translation lives in `languages/connect-the-dots-nl_NL.po`. After changing a user facing string, update the template and the translation:

```bash
npx @wordpress/env@10 run cli wp i18n make-pot wp-content/plugins/connect-the-dots wp-content/plugins/connect-the-dots/languages/connect-the-dots.pot --exclude=vendor,tests,.wordpress-org
```

The `.mo` files are built by the release workflow with `wp i18n make-mo` and are not committed. Build one locally to try the translation, and remove it again before running the tests, because the tests expect English.

## Releasing

Raise the version in `connect-the-dots.php`, in `Plugin::$version` and as `Stable tag` in `readme.txt`, and add a changelog entry. Pushing a tag `woocommerce-v` followed by the version, for example `woocommerce-v0.1.0`, runs `.github/workflows/woocommerce-release.yml`. It checks that the four versions match, builds the translations, deploys to the wordpress.org SVN with the secrets `SVN_USERNAME` and `SVN_PASSWORD`, and attaches the zip to a GitHub release. `.github/workflows/woocommerce-mirror.yml` copies `woocommerce/` on every push to main to the public repository ctdflow/connect-the-dots-woocommerce, and turns the tag into `v0.1.0` there for Composer. It pushes with the deploy key in the secret `WOOCOMMERCE_MIRROR_DEPLOY_KEY`. The listing banner and icons are in `.wordpress-org/`. Screenshots 1 to 3 are added there as `screenshot-1.png` to `screenshot-3.png`. Do not push a release tag before the public listing is ready.

## Multisite

The uninstall test that covers a network needs a multisite test install. Run it with `vendor/bin/phpunit -c phpunit.multisite.xml.dist --filter UninstallTest` through `tests-cli`. In the normal run that test is skipped.

# Rapidez Composer template diff plugin

This composer plugin will create hashes of the original template in published templates after a `composer update`.
This way you have a clear indication that a template you have overwritten has been changed.

It will add a `{{-- vendor-hash:VENDOR_FILE_MD5_HASH --}}` comment to the top of your blade files.

It will use the `views` publishables for this so make sure your views are publishable under the `views` tag.

## Installation

```shell
composer require (--dev) rapidez/composer-template-diff-plugin
```

## Usage

You can also run manually update the hashes with:

```shell
composer update-vendor-hashes`
```

This will also automatically run after updating any packages.

### Configure automatic plugin execution

Automatic execution (after `composer update`) can be disabled explicitly with:

```shell
RAPIDEZ_TEMPLATE_DIFF_DISABLED=1
```

When `RAPIDEZ_TEMPLATE_DIFF_DISABLED` is not set, automatic execution is enabled only when `APP_ENV` is `local`

Manual execution with `composer update-vendor-hashes` always runs and does not use these automatic plugin toggle rules.

## License

GNU General Public License v3. Please see [License File](LICENSE) for more information.

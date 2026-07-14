# Rapidez Composer template diff plugin

This composer plugin will create hashes of the original template in published templates after a `composer update`.
This way you have a clear indication that a template you have overwritten has been changed.

It will add a `{{-- vendor-hash:VENDOR_FILE_MD5_HASH --}}` comment to the top of your blade files.

It will use the `views` publishables for this so make sure your views are publishable under the `views` tag.

## Installation

`composer require (--dev) rapidez/composer-template-diff-plugin`

## License

GNU General Public License v3. Please see [License File](LICENSE) for more information.

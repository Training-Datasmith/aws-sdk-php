<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Psr\Http\Message\Stream_Interface;
interface Aes_Stream_Interface_V2 extends Stream_Interface
{
    /**
     * Returns an AES recognizable name, such as 'AES/GCM/NoPadding'. V2
     * interface is accessible from a static context.
     *
     * @return string
     */
    public static function get_static_aes_name();
    /**
     * Returns an identifier recognizable by `openssl_*` functions, such as
     * `aes-256-cbc` or `aes-128-ctr`.
     *
     * @return string
     */
    public function get_open_ssl_name();
    /**
     * Returns the IV that should be used to initialize the next block in
     * encrypt or decrypt.
     *
     * @return string
     */
    public function get_current_iv();
}
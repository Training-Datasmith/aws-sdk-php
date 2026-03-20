<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Psr\Http\Message\Stream_Interface;
interface Aes_Stream_Interface extends Stream_Interface
{
    /**
     * Returns an identifier recognizable by `openssl_*` functions, such as
     * `aes-256-cbc` or `aes-128-ctr`.
     *
     * @return string
     */
    public function get_open_ssl_name();
    /**
     * Returns an AES recognizable name, such as 'AES/GCM/NoPadding'.
     *
     * @return string
     */
    public function get_aes_name();
    /**
     * Returns the IV that should be used to initialize the next block in
     * encrypt or decrypt.
     *
     * @return string
     */
    public function get_current_iv();
}
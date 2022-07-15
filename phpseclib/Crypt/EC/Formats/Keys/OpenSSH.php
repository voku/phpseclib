<?php

/**
 * OpenSSH Formatted EC Key Handler
 *
 * PHP version 5
 *
 * Place in $HOME/.ssh/authorized_keys
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

declare(strict_types=1);

namespace phpseclib3\Crypt\EC\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\Common\Formats\Keys\OpenSSH as Progenitor;
use phpseclib3\Crypt\EC\BaseCurves\Base as BaseCurve;
use phpseclib3\Crypt\EC\Curves\Ed25519;
use phpseclib3\Exception\UnsupportedCurveException;
use phpseclib3\Math\BigInteger;

/**
 * OpenSSH Formatted EC Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class OpenSSH extends Progenitor
{
    use Common;

    /**
     * Supported Key Types
     *
     * @var array
     */
    protected static $types = [
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'ssh-ed25519'
    ];

    /**
     * Break a public or private key down into its constituent components
     *
     * @param string|array $key
     * @param string|false $password
     */
    public static function load($key, $password = ''): array
    {
        $parsed = parent::load($key, $password);

        if (isset($parsed['paddedKey'])) {
            $paddedKey = $parsed['paddedKey'];
            [$type] = Strings::unpackSSH2('s', $paddedKey);
            if ($type != $parsed['type']) {
                throw new \RuntimeException("The public and private keys are not of the same type ($type vs $parsed[type])");
            }
            if ($type == 'ssh-ed25519') {
                [, $key, $comment] = Strings::unpackSSH2('sss', $paddedKey);
                $key = libsodium::load($key);
                $key['comment'] = $comment;
                return $key;
            }
            [$curveName, $publicKey, $privateKey, $comment] = Strings::unpackSSH2('ssis', $paddedKey);
            $curve = self::loadCurveByParam(['namedCurve' => $curveName]);
            $curve->rangeCheck($privateKey);
            return [
                'curve' => $curve,
                'dA' => $privateKey,
                'QA' => self::extractPoint("\0$publicKey", $curve),
                'comment' => $comment
            ];
        }

        if ($parsed['type'] == 'ssh-ed25519') {
            if (Strings::shift($parsed['publicKey'], 4) != "\0\0\0\x20") {
                throw new \RuntimeException('Length of ssh-ed25519 key should be 32');
            }

            $curve = new Ed25519();
            $qa = self::extractPoint($parsed['publicKey'], $curve);
        } else {
            [$curveName, $publicKey] = Strings::unpackSSH2('ss', $parsed['publicKey']);
            $curveName = '\phpseclib3\Crypt\EC\Curves\\' . $curveName;
            $curve = new $curveName();

            $qa = self::extractPoint("\0" . $publicKey, $curve);
        }

        return [
            'curve' => $curve,
            'QA' => $qa,
            'comment' => $parsed['comment']
        ];
    }

    /**
     * Returns the alias that corresponds to a curve
     */
    private static function getAlias(BaseCurve $curve): string
    {
        self::initialize_static_variables();

        $reflect = new \ReflectionClass($curve);
        $name = $reflect->getShortName();

        $oid = self::$curveOIDs[$name];
        $aliases = array_filter(self::$curveOIDs, function ($v) use ($oid) {
            return $v == $oid;
        });
        $aliases = array_keys($aliases);

        for ($i = 0; $i < count($aliases); $i++) {
            if (in_array('ecdsa-sha2-' . $aliases[$i], self::$types)) {
                $alias = $aliases[$i];
                break;
            }
        }

        if (!isset($alias)) {
            throw new UnsupportedCurveException($name . ' is not a curve that the OpenSSH plugin supports');
        }

        return $alias;
    }

    /**
     * Convert an EC public key to the appropriate format
     *
     * @param \phpseclib3\Math\Common\FiniteField\Integer[] $publicKey
     * @param array $options optional
     */
    public static function savePublicKey(BaseCurve $curve, array $publicKey, array $options = []): string
    {
        $comment = $options['comment'] ?? self::$comment;

        if ($curve instanceof Ed25519) {
            $key = Strings::packSSH2('ss', 'ssh-ed25519', $curve->encodePoint($publicKey));

            if ($options['binary'] ?? self::$binary) {
                return $key;
            }

            $key = 'ssh-ed25519 ' . base64_encode($key) . ' ' . $comment;
            return $key;
        }

        $alias = self::getAlias($curve);

        $points = "\4" . $publicKey[0]->toBytes() . $publicKey[1]->toBytes();
        $key = Strings::packSSH2('sss', 'ecdsa-sha2-' . $alias, $alias, $points);

        if ($options['binary'] ?? self::$binary) {
            return $key;
        }

        $key = 'ecdsa-sha2-' . $alias . ' ' . base64_encode($key) . ' ' . $comment;

        return $key;
    }

    /**
     * Convert a private key to the appropriate format.
     *
     * @param \phpseclib3\Crypt\EC\Curves\Ed25519 $curve
     * @param \phpseclib3\Math\Common\FiniteField\Integer[] $publicKey
     * @param string|false $password
     * @param array $options optional
     */
    public static function savePrivateKey(
        BigInteger $privateKey,
        BaseCurve $curve,
        array $publicKey,
        $password = '',
        array $options = []
    ): string {
        if ($curve instanceof Ed25519) {
            if (!isset($privateKey->secret)) {
                throw new \RuntimeException('Private Key does not have a secret set');
            }
            if (strlen($privateKey->secret) != 32) {
                throw new \RuntimeException('Private Key secret is not of the correct length');
            }

            $pubKey = $curve->encodePoint($publicKey);

            $publicKey = Strings::packSSH2('ss', 'ssh-ed25519', $pubKey);
            $privateKey = Strings::packSSH2('sss', 'ssh-ed25519', $pubKey, $privateKey->secret . $pubKey);

            return self::wrapPrivateKey($publicKey, $privateKey, $password, $options);
        }

        $alias = self::getAlias($curve);

        $points = "\4" . $publicKey[0]->toBytes() . $publicKey[1]->toBytes();
        $publicKey = self::savePublicKey($curve, $publicKey, ['binary' => true]);

        $privateKey = Strings::packSSH2('sssi', 'ecdsa-sha2-' . $alias, $alias, $points, $privateKey);

        return self::wrapPrivateKey($publicKey, $privateKey, $password, $options);
    }
}

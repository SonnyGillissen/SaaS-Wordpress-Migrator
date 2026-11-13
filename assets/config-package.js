(function (global) {
    'use strict';

    var FORMAT = 'saas-wordpress-migrator-config';
    var VERSION = 1;
    var ITERATIONS = 600000;
    var MAX_PACKAGE_BYTES = 128 * 1024;
    var HEADER = {
        format: FORMAT,
        version: VERSION,
        kdf: 'PBKDF2-SHA256',
        iterations: ITERATIONS,
        cipher: 'AES-GCM'
    };
    var COMMON = {
        transfer: ['protocol', 'host', 'port', 'username', 'password', 'path', 'fingerprint'],
        database: ['host', 'port', 'name', 'username', 'password', 'prefix']
    };
    var PACKAGE_KEYS = ['format', 'version', 'kdf', 'iterations', 'cipher', 'salt', 'iv', 'data'];

    function requireCrypto() {
        if (global.isSecureContext !== true || !global.crypto ||
                !global.crypto.subtle || typeof global.crypto.getRandomValues !== 'function') {
            throw new Error('Configuration encryption requires Web Crypto in a secure context (HTTPS or localhost).');
        }
    }

    function requireSide(side) {
        if (side !== 'source' && side !== 'destination') {
            throw new Error('Configuration side must be source or destination.');
        }
    }

    function requirePassphrase(passphrase) {
        if (typeof passphrase !== 'string' || passphrase.length < 12 || passphrase.length > 1024) {
            throw new Error('Passphrase must contain between 12 and 1024 characters.');
        }
    }

    function objectKeys(value, allowed, exact) {
        if (!value || typeof value !== 'object' || Array.isArray(value) ||
                (Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null)) {
            throw new Error('Invalid configuration object.');
        }
        var keys = Reflect.ownKeys(value);
        if ((exact && keys.length !== allowed.length) || keys.length > allowed.length) {
            throw new Error('Unexpected configuration fields.');
        }
        keys.forEach(function (key) {
            var descriptor = Object.getOwnPropertyDescriptor(value, key);
            if (typeof key !== 'string' || allowed.indexOf(key) === -1 ||
                    !descriptor.enumerable || !Object.prototype.hasOwnProperty.call(descriptor, 'value')) {
                throw new Error('Unexpected configuration fields.');
            }
        });
        return keys;
    }

    function sanitize(configuration, side) {
        var schema = {
            transfer: COMMON.transfer,
            database: COMMON.database,
            wordpress: ['url', 'username', 'password']
        };
        if (side === 'destination') {
            schema.addresses = ['homeUrl', 'siteUrl'];
        }
        var clean = {};
        var sections = objectKeys(configuration, Object.keys(schema), false);
        if (sections.indexOf('wordpress') === -1 &&
                (sections.indexOf('transfer') === -1 || sections.indexOf('database') === -1)) {
            throw new Error('Configuration requires WordPress settings or both transfer and database settings.');
        }
        sections.forEach(function (section) {
            var fields = configuration[section];
            var result = {};
            objectKeys(fields, schema[section], false).forEach(function (field) {
                var value = fields[field];
                if (typeof value !== 'string' || value.length > 4096) {
                    throw new Error('Configuration fields must be strings of at most 4096 characters.');
                }
                if (section === 'transfer' && field === 'protocol' &&
                        value !== '' && ['ftp', 'ftps', 'sftp'].indexOf(value) === -1) {
                    throw new Error('Unsupported transfer protocol.');
                }
                result[field] = value;
            });
            clean[section] = result;
        });
        return clean;
    }

    function encodeBase64(bytes) {
        var binary = '';
        for (var i = 0; i < bytes.length; i += 1) {
            binary += String.fromCharCode(bytes[i]);
        }
        return global.btoa(binary);
    }

    function decodeBase64(text, expectedLength) {
        if (typeof text !== 'string' || text.length === 0 || text.length % 4 !== 0 ||
                !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(text)) {
            throw new Error('Invalid base64.');
        }
        var binary = global.atob(text);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        if ((expectedLength && bytes.length !== expectedLength) || encodeBase64(bytes) !== text) {
            throw new Error('Invalid base64 length or encoding.');
        }
        return bytes;
    }

    async function deriveKey(passphrase, salt, usage) {
        var material = await global.crypto.subtle.importKey(
            'raw', new TextEncoder().encode(passphrase), 'PBKDF2', false, ['deriveKey']
        );
        return global.crypto.subtle.deriveKey({
            name: 'PBKDF2',
            salt: salt,
            iterations: ITERATIONS,
            hash: 'SHA-256'
        }, material, { name: 'AES-GCM', length: 256 }, false, [usage]);
    }

    function cipherParameters(iv) {
        return {
            name: 'AES-GCM',
            iv: iv,
            additionalData: new TextEncoder().encode(JSON.stringify(HEADER)),
            tagLength: 128
        };
    }

    async function encrypt(side, configuration, passphrase) {
        requireCrypto();
        requireSide(side);
        requirePassphrase(passphrase);
        var plaintext = new TextEncoder().encode(JSON.stringify({
            side: side,
            configuration: sanitize(configuration, side)
        }));
        var salt = global.crypto.getRandomValues(new Uint8Array(16));
        var iv = global.crypto.getRandomValues(new Uint8Array(12));
        var envelope = {
            format: FORMAT,
            version: VERSION,
            kdf: HEADER.kdf,
            iterations: ITERATIONS,
            cipher: HEADER.cipher,
            salt: encodeBase64(salt),
            iv: encodeBase64(iv),
            data: ''
        };
        // Base64 and the GCM authentication tag count toward the exported size.
        if (JSON.stringify(envelope).length + 4 * Math.ceil((plaintext.length + 16) / 3) > MAX_PACKAGE_BYTES) {
            throw new Error('Configuration package exceeds 128 KiB.');
        }
        var key = await deriveKey(passphrase, salt, 'encrypt');
        var encrypted = await global.crypto.subtle.encrypt(cipherParameters(iv), key, plaintext);
        envelope.data = encodeBase64(new Uint8Array(encrypted));
        return JSON.stringify(envelope);
    }

    async function decrypt(text, side, passphrase) {
        requireCrypto();
        requireSide(side);
        requirePassphrase(passphrase);
        var payload;
        var configuration;
        try {
            if (typeof text !== 'string' || text.length > MAX_PACKAGE_BYTES ||
                    new TextEncoder().encode(text).length > MAX_PACKAGE_BYTES) {
                throw new Error('Invalid package size.');
            }
            var envelope = JSON.parse(text);
            objectKeys(envelope, PACKAGE_KEYS, true);
            if (envelope.format !== FORMAT || envelope.version !== VERSION ||
                    envelope.kdf !== HEADER.kdf || envelope.iterations !== ITERATIONS ||
                    envelope.cipher !== HEADER.cipher) {
                throw new Error('Unsupported package header.');
            }
            var salt = decodeBase64(envelope.salt, 16);
            var iv = decodeBase64(envelope.iv, 12);
            var encrypted = decodeBase64(envelope.data);
            if (encrypted.length < 16) {
                throw new Error('Invalid ciphertext.');
            }
            var key = await deriveKey(passphrase, salt, 'decrypt');
            var plaintext = await global.crypto.subtle.decrypt(cipherParameters(iv), key, encrypted);
            payload = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(plaintext));
            objectKeys(payload, ['side', 'configuration'], true);
            requireSide(payload.side);
            configuration = sanitize(payload.configuration, payload.side);
        } catch (error) {
            throw new Error('Unable to decrypt configuration package: incorrect passphrase or corrupted package.');
        }
        if (payload.side !== side) {
            throw new Error('Configuration package is for the wrong side (expected ' + side + ').');
        }
        return configuration;
    }

    global.ConfigPackage = Object.freeze({ encrypt: encrypt, decrypt: decrypt });
}(window));

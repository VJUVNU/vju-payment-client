<?php

namespace Vju\PaymentClient\Exceptions;

/**
 * Cổng từ chối xác thực (401) hoặc app bị tắt / IP không được phép (403):
 * unknown_merchant, signature_expired, nonce_missing, signature_invalid, replay_detected,
 * merchant_disabled, ip_not_allowed. Thường do sai PAYMENT_GATEWAY_KEY/SECRET hoặc đồng hồ máy lệch.
 */
class AuthenticationException extends GatewayException
{}

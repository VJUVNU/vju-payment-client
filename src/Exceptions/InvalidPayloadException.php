<?php

namespace Vju\PaymentClient\Exceptions;

/**
 * Phản hồi hoặc webhook của cổng thiếu trường bắt buộc / không phải JSON hợp lệ.
 */
class InvalidPayloadException extends GatewayException
{}

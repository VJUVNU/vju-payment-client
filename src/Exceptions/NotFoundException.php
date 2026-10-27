<?php

namespace Vju\PaymentClient\Exceptions;

/**
 * Không có đơn ref này của app (404 order_not_found).
 */
class NotFoundException extends GatewayException
{}

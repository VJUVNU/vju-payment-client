<?php

namespace Vju\PaymentClient\Exceptions;

/**
 * 422: validation_failed (fields liệt kê từng trường), method_not_enabled, ref_not_allowed.
 */
class ValidationException extends GatewayException
{
    /**
     * @return list<string>
     */
    public function messagesFor(string $field): array
    {
        return array_values((array) ($this->fields[$field] ?? []));
    }
}

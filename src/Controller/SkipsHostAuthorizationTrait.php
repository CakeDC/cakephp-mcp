<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Controller;

/**
 * For the plugin's controllers: cakephp/authorization (when the host uses it) would otherwise demand an
 * authorization check for their actions. Per-tool authorization is the plugin's own.
 */
trait SkipsHostAuthorizationTrait
{
    /**
     * @return void
     */
    private function skipHostAuthorization(): void
    {
        $authorization = $this->request->getAttribute('authorization');
        if (is_object($authorization) && method_exists($authorization, 'skipAuthorization')) {
            $authorization->skipAuthorization();
        }
    }
}

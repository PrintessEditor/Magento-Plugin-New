<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

/** SUP-09: "My releasable documents" (supervisors only). */
class Index extends AbstractPage
{
    public function execute()
    {
        if (!$this->isSupervisorHere()) {
            return $this->notFound();
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My releasable documents'));
        return $page;
    }
}

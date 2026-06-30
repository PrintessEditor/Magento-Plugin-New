<?php
namespace Printess\PrintessEditor\Controller\Project;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Printess\PrintessEditor\Model\SavedProjectFactory;
use Printess\PrintessEditor\Model\ResourceModel\SavedProject as SavedProjectResource;

class Save implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface    $request,
        private readonly JsonFactory         $jsonFactory,
        private readonly CustomerSession     $customerSession,
        private readonly SavedProjectFactory $savedProjectFactory,
        private readonly SavedProjectResource $savedProjectResource
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData(['success' => false, 'message' => 'Not logged in'])->setHttpResponseCode(401);
        }

        $body      = json_decode((string)$this->request->getContent(), true) ?? [];
        $name      = trim((string)($body['name'] ?? ''));
        $saveToken = trim((string)($body['saveToken'] ?? ''));

        if ($saveToken === '') {
            return $result->setData(['success' => false, 'message' => 'Missing saveToken']);
        }
        if ($name === '') {
            $name = 'My Project';
        }

        try {
            $project = $this->savedProjectFactory->create();
            $project->setData([
                'customer_id' => (int)$this->customerSession->getCustomerId(),
                'name'        => $name,
                'save_token'  => $saveToken,
            ]);
            $this->savedProjectResource->save($project);

            return $result->setData([
                'success'    => true,
                'id'         => $project->getId(),
                'name'       => $name,
                'created_at' => $project->getData('created_at'),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()])->setHttpResponseCode(500);
        }
    }
}

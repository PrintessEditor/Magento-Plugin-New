<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Printess\PrintessEditor\Model\ProjectFactory;
use Printess\PrintessEditor\Model\Project\EditorConfigBuilder;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;

/**
 * Editor configuration for a supervisor opening a submitted design (view only, or edit under the edit lock).
 */
class ReviewEditorConfig
{
    public function __construct(
        private readonly ProjectFactory $projectFactory,
        private readonly ProjectResource $projectResource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EditorConfigBuilder $editorConfigBuilder,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @param string $mode 'view' or 'edit'
     */
    public function build(Submission $submission, string $mode, int $viewerId, ?string $token = null): array
    {
        $project = $this->projectFactory->create();
        $this->projectResource->load($project, $submission->getProjectId());
        if (!$project->getId() || !$submission->getData('product_id')) {
            throw new LocalizedException(__('The product of this design is no longer available.'));
        }
        try {
            $product = $this->productRepository->getById(
                (int) $submission->getData('product_id'),
                false,
                $submission->getStoreId()
            );
        } catch (\Throwable) {
            throw new LocalizedException(__('The product of this design is no longer available.'));
        }
        $token = $token ?? (string) $submission->getData('submitted_save_token');
        $config = $this->editorConfigBuilder->build($project, $product, $viewerId, $token);
        $config['projectId'] = null; // never let the editor update the submitter's project directly
        $config['restoreSavedDesign'] = true;
        $config['presetSelections'] = $submission->getBuyRequest();
        $config['approval'] = [
            'mode'         => $mode,
            'submissionId' => (int) $submission->getId(),
            'saveEditUrl'  => $this->urlBuilder->getUrl('printess/approval/saveEdit'),
            'reviewUrl'    => $this->urlBuilder->getUrl('printess/approval/review'),
            'viewUrl'      => $this->urlBuilder->getUrl('printess/approval/view', ['id' => (int) $submission->getId()]),
        ];
        return $config;
    }
}

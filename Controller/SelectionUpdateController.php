<?php
namespace Selection\Controller;

use Propel\Runtime\ActiveQuery\Criteria;
use Selection\Event\SelectionContainerEvent;
use Selection\Event\SelectionEvent;
use Selection\Event\SelectionEvents;
use Selection\Form\SelectionCreateForm;
use Selection\Form\SelectionUpdateForm;
use Selection\Model\Selection as SelectionModel;
use Selection\Model\SelectionContainerAssociatedSelection;
use Selection\Model\SelectionContentQuery;
use Selection\Model\SelectionI18nQuery;
use Selection\Model\SelectionProductQuery;
use Selection\Model\SelectionQuery;
use Selection\Selection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Controller\Admin\AbstractSeoCrudController;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Tools\URL;

class SelectionUpdateController extends AbstractSeoCrudController
{
    protected $currentRouter = Selection::ROUTER;
    protected $deleteGroupEventIdentifier  = SelectionEvents::SELECTION_CONTAINER_DELETE;

    /**
     * Save content of the selection
     *
     * @return \Symfony\Component\HttpFoundation\Response|\Thelia\Core\HttpFoundation\Response
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function saveSelection()
    {

        $form = new SelectionUpdateForm($this->getRequest());

        $validForm  =   $this->validateForm($form);
        $data       =   $validForm->getData();

        $selectionID            = $data['selection_id'];
        $selectionTitle         = $data['selection_title'];
        $selectionChapo         = $data['selection_chapo'];
        $selectionDescription   = $data['selection_description'];
        $selectionPostscriptum  = $data['selection_postscriptum'];

        $lang = $this->getRequest()->getSession()->get('thelia.current.lang');

        $aSelection = SelectionI18nQuery::create()
            ->filterById($selectionID)
            ->filterByLocale($lang->getLocale())
            ->findOne();

        $aSelection
            ->setTitle($selectionTitle)
            ->setChapo($selectionChapo)
            ->setDescription($selectionDescription)
            ->setPostscriptum($selectionPostscriptum);

        $aSelection->save();

        if ($validForm->get('save_and_close')->isClicked()) {
            return $this->render("electionlist");
        }


        return $this->generateRedirectFromRoute('selection.update', [], ['selectionId' => $selectionID], null);
    }

    public function createSelection()
    {
        $form       = new SelectionCreateForm($this->getRequest());
        try {
            $validForm  = $this->validateForm($form);
            $data       = $validForm->getData();
            $title         = $data['title'];
            $chapo         = $data['chapo'];
            $description   = $data['description'];
            $postscriptum  = $data['postscriptum'];
            $lang       = $this->getRequest()->getSession()->get('thelia.current.lang');
            $date = new \DateTime();
            $selection  = new SelectionModel();
            $lastSelection   = SelectionQuery::create()->orderByPosition(Criteria::DESC)->findOne();
            if (null !== $lastSelection) {
                $position =  $lastSelection->getPosition() + 1;
            } else {
                $position = 1;
            }
            $selection
                ->setCreatedAt($date->format('Y-m-d H:i:s'))
                ->setUpdatedAt($date->format('Y-m-d H:i:s'))
                ->setVisible(1)
                ->setPosition($position)
                ->setLocale($lang->getLocale())
                ->setTitle($title)
                ->setChapo($chapo)
                ->setDescription($description)
                ->setPostscriptum($postscriptum);
            $selection->save();
            return $this->generateRedirect("/admin/selection");
        } catch (FormValidationException $ex) {
            // Form cannot be validated
            $error_msg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            // Any other error
            $error_msg = $ex->getMessage();
        }

        if (false !== $error_msg) {
            $this->setupFormErrorContext(
                $this->getTranslator()->trans("%obj creation", ['%obj' => $this->objectName]),
                $error_msg,
                $form,
                $ex
            );
            // At this point, the form has error, and should be redisplayed.
            return $this->renderList();
        }
    }


    public function updateSelectionPositionAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array(Selection::DOMAIN_NAME), AccessManager::UPDATE)) {
            return $response;
        }
        try {
            $mode = $this->getRequest()->get('mode', null);

            if ($mode == 'up') {
                $mode = UpdatePositionEvent::POSITION_UP;
            } elseif ($mode == 'down') {
                $mode = UpdatePositionEvent::POSITION_DOWN;
            } else {
                $mode = UpdatePositionEvent::POSITION_ABSOLUTE;
            }

            $position = $this->getRequest()->get('position', null);

            $event = $this->createUpdateSelectionPositionEvent($mode, $position);

            $this->dispatch(SelectionEvents::SELECTION_UPDATE_POSITION, $event);
        } catch (\Exception $ex) {
            Tlog::getInstance()->error($ex->getMessage());
        }

        return $this->forward('Selection\Controller\SelectionController::viewAction');
    }

    public function deleteRelatedProduct()
    {
        $selectionID = $this->getRequest()->get('selectionID');
        $productID   = $this->getRequest()->get('productID');

        try {
            $selection = SelectionProductQuery::create()
                ->filterByProductId($productID)
                ->findOneBySelectionId($selectionID);
            if (null !== $selection) {
                $selection->delete();
            }
        } catch (\Exception $e) {
            Tlog::getInstance()->error($e->getMessage());
        }

        return $this->generateRedirectFromRoute('selection.update', [], ['selectionId' => $selectionID], null);
    }

    public function deleteRelatedContent()
    {
        $selectionID = $this->getRequest()->get('selectionID');
        $contentID   = $this->getRequest()->get('contentID');

        try {
            $selection = SelectionContentQuery::create()
                ->filterByContentId($contentID)
                ->findOneBySelectionId($selectionID);
            if (null !== $selection) {
                $selection->delete();
            }
        } catch (\Exception $e) {
            Tlog::getInstance()->error($e->getMessage());
        }

        return $this->generateRedirectFromRoute('selection.update', [], ['selectionId' => $selectionID], null);
    }
    /*--------------------------    Part Controller SEO */
    public function __construct()
    {
        parent::__construct(
            'selection',
            'selection_id',
            'order',
            AdminResources::MODULE,
            SelectionEvents::SELECTION_CREATE,
            SelectionEvents::SELECTION_UPDATE,
            SelectionEvents::SELECTION_DELETE,
            null,
            SelectionEvents::RELATED_PRODUCT_UPDATE_POSITION,
            SelectionEvents::SELECTION_UPDATE_SEO,
            Selection::DOMAIN_NAME
        );
    }

    protected function getCreationForm()
    {
        return $this->createForm('admin.selection.update');
    }

    protected function getUpdateForm($data = array())
    {
        if (!is_array($data)) {
            $data = array();
        }

        return $this->createForm('admin.selection.update', 'form', $data);
    }

    /**
     * $object Selection
     * @param \Selection\Model\Selection $selection
     * @return \Thelia\Form\BaseForm
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function hydrateObjectForm($selection)
    {
        $this->hydrateSeoForm($selection);
        $associatedContainer = $selection->getSelectionContainerAssociatedSelections();
        $container = null;
        if (!empty($associatedContainer) && count($associatedContainer) > 0) {
            /** @var SelectionContainerAssociatedSelection[] $associatedContainer */
            $container = $associatedContainer[0]->getSelectionContainerId();
        }
        $data = array(
            'selection_id'          => $selection->getId(),
            'selection_container'   => $container,
            'id'                    => $selection->getId(),
            'locale'                => $selection->getLocale(),
            'selection_chapo'       => $selection->getChapo(),
            'selection_description' => $selection->getDescription(),
            'selection_postscriptum'=> $selection->getPostscriptum(),
            'current_id'            => $selection->getId(),
        );

        return $this->getUpdateForm($data);
    }

    protected function getCreationEvent($formData)
    {
        $event = new SelectionEvent();

        $event->setTitle($formData['title']);
        $event->setChapo($formData['chapo']);
        $event->setDescription($formData['description']);
        $event->setPostscriptum($formData['postscriptum']);

        return $event;
    }

    protected function getUpdateEvent($formData)
    {
        $selection = SelectionQuery::create()->findPk($formData['selection_id']);
        $event = new SelectionEvent($selection);

        $event->setId($formData['selection_id']);
        $event->setContainerId($formData['selection_container_id']);
        $event->setTitle($formData['selection_title']);
        $event->setChapo($formData['selection_chapo']);
        $event->setDescription($formData['selection_description']);
        $event->setPostscriptum($formData['selection_postscriptum']);
        $event->setLocale($this->getRequest()->getSession()->get('thelia.current.lang')->getLocale());
        return $event;
    }

    protected function getDeleteEvent()
    {
        $event = new SelectionEvent();
        $selectionId = $this->getRequest()->request->get('selection_id');
        $event->setId($selectionId);
        return $event;
    }

    protected function getDeleteGroupEvent()
    {
        $event = new SelectionContainerEvent();
        $selectionGroupId = $this->getRequest()->request->get('selection_group_id');
        $event->setId($selectionGroupId);
        return $event;
    }

    protected function eventContainsObject($event)
    {
        return $event->hasSelection();
    }

    protected function getObjectFromEvent($event)
    {
        return $event->getSelection();
    }

    protected function getExistingObject()
    {
        $selection = SelectionQuery::create()
            ->findPk($this->getRequest()->get('selectionId', 0));

        if (null !== $selection) {
            $selection->setLocale($this->getCurrentEditionLocale());
        }

        return $selection;
    }

    protected function getObjectLabel($object)
    {
        return '';
    }

    /**
     * Returns the object ID from the object
     * @param \Selection\Model\Selection $object
     * @return int selection id
     */
    protected function getObjectId($object)
    {
        return $object->getId();
    }

    protected function renderListTemplate($currentOrder)
    {
        $this->getParser()->assign("order", $currentOrder);
        return $this->render('selection-list');
    }

    protected function renderEditionTemplate()
    {
        $selectionId = $this->getRequest()->get('selectionId');
        $currentTab = $this->getRequest()->get('current_tab');
        return $this->render(
            'selection-edit',
            [
                'selection_id' => $selectionId,
                'current_tab' => $currentTab
            ]
        );
    }

    protected function redirectToEditionTemplate()
    {
        $id = $this->getRequest()->get('selection_id');

        return new RedirectResponse(
            URL::getInstance()->absoluteUrl(
                "/admin/selection/update/".$id
            )
        );
    }

    protected function redirectToListTemplate()
    {
        return new RedirectResponse(
            URL::getInstance()->absoluteUrl("/admin/selection")
        );
    }

    /**
     * Online status toggle product
     */
    public function setToggleVisibilityAction()
    {
        // Check current user authorization
        if (null !== $response = $this->checkAuth($this->resourceCode, array(), AccessManager::UPDATE)) {
            return $response;
        }

        $event = new SelectionEvent($this->getExistingObject());

        try {
            $this->dispatch(SelectionEvents::SELECTION_TOGGLE_VISIBILITY, $event);
        } catch (\Exception $ex) {
            // Any error
            return $this->errorPage($ex);
        }

        // Ajax response -> no action
        return $this->nullResponse();
    }

    protected function createUpdateProductPositionEvent($positionChangeMode, $positionValue)
    {
        return new UpdatePositionEvent(
            $this->getRequest()->get('product_id', null),
            $positionChangeMode,
            $positionValue,
            $this->getRequest()->get('selection_id', null)
        );
    }

    protected function createUpdateSelectionPositionEvent($positionChangeMode, $positionValue)
    {
        return new UpdatePositionEvent(
            $this->getRequest()->get('selection_id', null),
            $positionChangeMode,
            $positionValue,
            Selection::getModuleId()
        );
    }

    protected function performAdditionalUpdatePositionAction($positionEvent)
    {
        $selectionID = $this->getRequest()->get('selection_id');

        return $this->generateRedirectFromRoute('selection.update', [], ['selectionId' => $selectionID], null);
    }

    public function processUpdateSeoAction()
    {
        $selectionId = $this->getRequest()->get('current_id');
        $this->getRequest()->request->set("selectionId", $selectionId);
        return parent::processUpdateSeoAction();
    }
}

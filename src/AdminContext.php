<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\file\Entity\File;
use Drupal\node\Entity\NodeType;
use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Defines application features from the specific context.
 */
class AdminContext extends AnonymousContext implements Context, SnippetAcceptingContext {

  /**
   * @Given /^I am on the add "([^"]*)" page$/
   *
   * @param string $contentTypeName
   *   The content type's machine name or label.
   * @throws \Exception
   */
  public function iAmOnTheAddPage($contentTypeName) {
    $nodeTypes = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      /** @var \Drupal\node\Entity\NodeType $nodeType */
      if ($this->matchesMachineName($nodeType, $contentTypeName) || $this->matchesLabel($nodeType, $contentTypeName)) {
        $this->assertAtPath("/node/add/{$nodeType->id()}");
        return;
      }
    }

    throw new \Exception("Unknown content type: {$contentTypeName}");
  }

  /**
   * @param \Drupal\node\Entity\NodeType $nodeType
   *   The node type to check against.
   * @param string $machineName
   *   The machine name to match.
   * @return bool
   *   Whether or not the NodeType matches the machine name.
   */
  private function matchesMachineName(NodeType $nodeType, $machineName) {
    return $nodeType->id() == $machineName;
  }

  /**
   * @param \Drupal\node\Entity\NodeType $nodeType
   *   The node type to check against.
   * @param string $label
   *   The label to match.
   * @return bool
   *   Whether or not the NodeType matches the label.
   */
  private function matchesLabel(NodeType $nodeType, $label) {
    return $nodeType->label() == $label;
  }

  /**
   * @Given /^I am on the edit "([^"]*)" block page$/
   * @param string $blockName
   *   The name of the block we want to edit.
   * @throws \Exception
   */
  public function iAmOnTheEditBlockPage($blockName) {
    $blockQuery = \Drupal::entityTypeManager()->getStorage('block_content')->getQuery();
    $blockQuery->condition('info', $blockName);
    $ids = $blockQuery->execute();

    if (count($ids) == 0) {
      throw new Exception("No block with {$blockName} could be found.");
    }
    elseif (count($ids) > 1) {
      throw new Exception("{$blockName} could not be resolved to a single block.");
    }

    $id = reset($ids);
    $this->assertAtPath("block/$id");
  }

  /**
   * @Then /^I should not see the "([^"]*)" tab$/
   * @param string $label
   *   The label to look for.
   * @throws \Exception
   */
  public function iShouldNotSeeTheTab($label) {
    $currentPage = $this->getMink()->getSession()->getPage();
    $hasTabs = $currentPage->has('css', '.tabs');
    if ($hasTabs) {
      $tabs = $currentPage->find('css', '.tabs');
      if (strpos($label, $tabs->getText()) !== FALSE) {
        throw new Exception("The ${label} tab has been found in the tabs.");
      }
    };
  }

  /**
   * Attempts to click a link in a details block containing giving text. This is
   * for add/edit content forms containing media fields for instance.
   *
   * @Given I click :clickable in the fieldset containing :text
   */
  public function clickLinkInFieldset($clickable, $fieldsetText) {
    $clickableElement = $this->assertClickableInFieldset($clickable, $fieldsetText);
    $clickableElement->click();
  }

  /**
   * @param string $text
   *
   * @return \Behat\Mink\Element\NodeElement[]
   * @throws \Exception
   */
  private function findFieldsetsWithText($text) {
    /** @var \Behat\Mink\Element\NodeElement[] $fieldsets */
    $fieldsets = $this->getSession()->getPage()->findAll('css', 'fieldset, details');

    $matches = [];
    foreach ($fieldsets as $fieldset) {
      if (strpos($fieldset->getText(), $text) !== FALSE) {
        $matches[] = $fieldset;
      }
    }

    if (empty($matches)) {
      throw new \Exception(sprintf('Could not find a fieldset containing %s', $text));
    }

    return $matches;
  }

  /**
   * @When I fill in :value for :field in the fieldset containing :fieldset
   */
  public function fillFieldInFieldset($value, $field, $fieldset) {
    foreach ($this->findFieldsetsWithText($fieldset) as $element) {
      try {
        $element->fillField($field, $value);
        return;
      }
      catch (\Exception $e) {
        // We ignore failures because we don't want a failure to stop the loop.
      }
    }

    throw new ElementNotFoundException($this->getDriver(), 'form field', 'id|name|label|value|placeholder', $locator);
  }

  /**
   * Attempts to find a link in a details block containing giving text. This is
   * for add/edit content forms containing media fields for instance.
   * @then I (should )see the :clickable link/button in the fieldset containing
   *   :text
   * @return ElementNode;
   */
  public function assertClickableInFieldset($clickable, $fieldsetText) {
    $fieldsets = $this->findFieldsetsWithText($fieldsetText);

    foreach ($fieldsets as $fieldset) {
      if ($clickableElement = $fieldset->findLink($clickable)) {
        return $clickableElement;
      }
      elseif ($clickableElement = $fieldset->findButton($clickable)) {
        return $clickableElement;
      }
    }

    throw new \Exception(sprintf('Found a row fieldset "%s", but no "%s" link on the page %s', $fieldsetText, $clickable, $this->getSession()->getCurrentUrl()));
  }

  /**
   * @When I click summary :summaryText
   */
  public function clickDetailsSummary($summaryText) {
    $summaryElements = $this->getSession()->getPage()->findAll('css', 'summary');

    foreach ($summaryElements as $element) {
      if ($element->getText() === $summaryText) {
        $element->click();
        return;
      }
    }

    throw new \Exception("No \"{$summaryText}\" summary found");
  }

  /**
   * @When I look in the :name iframe
   */
  public function switchToIframe($name) {
    $this->getSession()->switchToIFrame($name);
  }

  /**
   * @When I look in the main window
   */
  public function switchToWindow() {
    $this->getSession()->switchToWindow();
  }

  /**
   * @Then I fill in :value for wysiwyg :locator
   */
  public function iFillInWysiwyg($value, $locator) {
    $el = $this->getSession()->getPage()->findField($locator);

    if (empty($el)) {
      throw new \ExpectationException('Could not find WYSIWYG with locator: ' . $locator, $this->getSession());
    }

    $fieldId = $el->getAttribute('id');

    if (empty($fieldId)) {
      throw new \Exception('Could not find an id for field with locator: ' . $locator);
    }

    $this->getSession()
      ->executeScript("CKEDITOR.instances[\"$fieldId\"].setData(\"$value\");");
  }

  /**
   * @When I click the :tabName tab
   */
  public function iClickTheTab($tabName) {
    /** @var \Behat\Mink\Element\NodeElement[] $tabs */
    $tabs = $this->getSession()->getPage()->findAll('css', '.vertical-tabs__menu, .horizontal-tabs__menu');

    foreach ($tabs as $tab) {
      if ($tab->findLink($tabName)) {
        $tab->findLink($tabName)->click();
        return;
      }
    }
    throw new \Exception("Tab '$tabName' couldn't be found");
  }

  /**
   * @When I submit the modal
   */
  public function iSubmitTheModal() {
    $modal = $this->getSession()->getPage()->find('css', '.ui-dialog');

    if (empty($modal)) {
      throw new AssertionFailedError("No active dialogs are open");
    }

    $submitLabels = ['Save'];

    /** @var \Behat\Mink\Element\NodeElement[] $buttons */
    $buttons = $modal->findAll('css', 'button, input[type=submit]');
    foreach ($buttons as $button) {
      if (in_array($button->getText(), $submitLabels)) {
        $button->click();
        return;
      }
    }

    throw new AssertionFailedError("Unable to find a submit button");
  }

}

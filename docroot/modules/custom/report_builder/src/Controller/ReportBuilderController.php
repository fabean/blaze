<?php

namespace Drupal\report_builder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class ReportBuilderController.
 */
class ReportBuilderController extends ControllerBase {

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;
  private $entityFieldManager;

  /**
   * Constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   */
  public function __construct(RequestStack $request_stack, EntityFieldManager $entityFieldManager) {
    $this->requestStack = $request_stack;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Get.
   *
   * @return string
   *   Return Hello string.
   */
  public function Get() {
    \Drupal::service('page_cache_kill_switch')->trigger();
    // we will build a form on the site
    $form = [];
    $user_manager = \Drupal::entityTypeManager()->getStorage('user');
    $report_manager = \Drupal::entityTypeManager()->getStorage('node');
    $this_user_id = \Drupal::currentUser()->id();
    $this_user = $user_manager->load($this_user_id);
    $kids_responsible_for = $this_user->get('field_kids_responsible_for')->getValue();

    // figure out what day it is
    $day_of_week = date('N');
    if ($day_of_week == 3) {
      // it's wednesday so today
      $timestamp = strtotime('today');
    }
    else {
      // grab last Wednesday
      $timestamp = strtotime('last Wednesday');
    }

    $date = date(DATETIME_DATETIME_STORAGE_FORMAT, $timestamp . ' UTC');

    if (!empty($kids_responsible_for)) {
      $allTheNids = [];
      foreach ($kids_responsible_for as $key => $individual_kid) {
        $kids_responsible_for[$key]['user_object'] = $user_manager->load($individual_kid['target_id']);

        $kids_uid = (integer) $individual_kid['target_id'];
        // see if this kid already has a report for today
        $query = \Drupal::entityQuery('node')
          ->condition('status', 1)
          ->condition('type', 'report')
          ->condition('field_blaze_kid.target_id', $kids_uid)
          ->condition('field_date.value', $date);

        $nids = $query->execute();

        $form[$individual_kid['target_id']] = [
          'id' => $individual_kid['target_id'],
          'name' => $kids_responsible_for[$key]['user_object']->name->value,
          'title' => $kids_responsible_for[$key]['user_object']->name->value . ': ' . $date,
          'status' => '1',
          'field_date' => $date,
          'field_blaze_kid' => $kids_uid,
        ];

        $report = [];
        // if the node exists get the values
        if (!empty($nids)) {
          $this_report = $report_manager->load(current($nids));

          $form[$individual_kid['target_id']]['nid'] = current($nids);

          $report = [
            'field_bible' => [
              'value' => $this_report->field_bible->value,
              'label' => 'Bible',
            ],
            'field_indy_winner' => [
              'value' => $this_report->field_indy_winner->value,
              'label' => 'Indy Winner',
            ],
            'field_lego_brick' => [
              'value' => $this_report->field_lego_brick->value,
              'label' => 'Lego Brick',
            ],
            'field_memory_verse' => [
              'value' => $this_report->field_memory_verse->value,
              'label' => 'Memory Verse',
            ],
            'field_student_sheet' => [
              'value' => $this_report->field_student_sheet->value,
              'label' => 'Student Sheet',
            ],
            'field_sword_drill' => [
              'value' => $this_report->field_sword_drill->value,
              'label' => 'Sword Drill'
            ]
          ];
        }
        else {
          // create new report
          $report = [
            'field_bible' => [
              'value' => '0',
              'label' => 'Bible',
            ],
            'field_indy_winner' => [
              'value' => '0',
              'label' => 'Indy Winner',
            ],
            'field_lego_brick' => [
              'value' => '0',
              'label' => 'Lego Brick',
            ],
            'field_memory_verse' => [
              'value' => '0',
              'label' => 'Memory Verse',
            ],
            'field_student_sheet' => [
              'value' => '0',
              'label' => 'Student Sheet',
            ],
            'field_sword_drill' => [
              'value' => '0',
              'label' => 'Sword Drill'
            ]
          ];
        }
        $form[$individual_kid['target_id']]['report'] = $report;
      }
    }

    // resort the form to put kids name in alpha order
    usort($form, function($a, $b) {
      return $a['name'] <=> $b['name'];
    });

    return [
      '#theme' => 'report_builder',
      '#form' => $form
    ];
  }

  /**
   * Post.
   *
   * @return string
   *   Return Hello string.
   */
  public function Post() {
    \Drupal::service('page_cache_kill_switch')->trigger();
    $report_manager = \Drupal::entityTypeManager()->getStorage('node');

    $output_markup = \Drupal::request()->request;
    $params = $this->requestStack->getCurrentRequest()->request->get('kids');
    $form_fields = [];
    $field_definition = $this->entityFieldManager->getFieldDefinitions('node', 'report');
    if ($field_definition) {
      foreach ($field_definition as $field_name => $field) {
        if ($field->getFieldStorageDefinition()->isBaseField() == FALSE) {
          $form_fields[$field_name] = $field;
        }
      }
    }

    $why_tho = [
      'on' => '1',
      'off' => '0'
    ];

    foreach ($params as $kids => $individual_kid) {
      if ($individual_kid['nid'] !== '') {
        // we will save an existing thing
        $this_report = $report_manager->load($individual_kid['nid']);
        $this_report->set('status', 1);
        foreach ($form_fields as $fname => $fconfig) {
          if (isset($individual_kid[$fname])) {
            $this_report->set($fname, $why_tho[$individual_kid[$fname]]);
          }
          else {
            $this_report->set($fname, $fconfig->getDefaultValueLiteral());
          }
        }
        $this_report->set('field_blaze_kid', $individual_kid['blaze_kid']);
        $this_report->set('field_date', $individual_kid['date']);

        $this_report->save();

      }
      else {
        // make new report
        $report_data = [
          'type' => 'report',
          'field_blaze_kid' => $individual_kid['blaze_kid'],
          'field_date' => $individual_kid['date'],
          'title' => $individual_kid['title'],
          'status' => '1'
        ];
        foreach ($form_fields as $fname => $fconfig) {
          if (isset($individual_kid[$fname])) {
            $report_data[$fname] = $why_tho[$individual_kid[$fname]];
          }
          else {
            $report_data[$fname] = $fconfig->getDefaultValueLiteral();
          }
        }
        $report_data['field_blaze_kid'] = $individual_kid['blaze_kid'];
        $report_data['field_date'] = $individual_kid['date'];
        $this_report = $report_manager->create($report_data);
        $this_report->save();
      }
    }

    drupal_set_message('Report Successfully submitted');
    return new RedirectResponse('/report_builder');
  }

}

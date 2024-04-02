<?php

class Research {
  static function getBechdelResult(array $answers): array {
    $bechdelResult = Answer::yes;

    $totalQuestionsPassed = 0;
    foreach ($answers as $answer) {
      if      ($answer === Answer::no->value)              {$bechdelResult = Answer::no; break;}
      else if ($answer === Answer::notDeterminable->value) {$bechdelResult = Answer::notDeterminable; break;}
      else if ($answer === null)                           {$bechdelResult = null; break;}
      else if (($answer === Answer::yes->value))           {$totalQuestionsPassed += 1;}
    }

    return [
      'result' => $bechdelResult,
      'total_questions_passed' => $totalQuestionsPassed,
    ];
  }

  static function passesArticleFilter($article, $articleFilter): bool {

    if ($articleFilter['questionsPassed'] === 'nd') {
      if ($article['bechdelResult']['result']->value != Answer::notDeterminable->value) return false;
    } else {
      if ($article['bechdelResult']['result']->value === Answer::notDeterminable->value) return false;

      if ($article['bechdelResult']['total_questions_passed'] !== (int)$articleFilter['questionsPassed']) return false;
    }

    if ($articleFilter['group'] === 'year') {
      if ($articleFilter['groupData'] != $article['article_year']) return false;
    } else if ($articleFilter['group'] === 'month') {
      if ($articleFilter['groupData'] != $article['article_year_month']) return false;
    } else if ($articleFilter['group'] === 'source') {
      if ($articleFilter['groupData'] != $article['sitename']) return false;
    } else if ($articleFilter['group'] === 'country') {
      if ($articleFilter['groupData'] != $article['countryid']) return false;
    }

    return true;
  }

  static function loadQuestionnaireResults(array $filter, string $group, array $articleFilter, $publicOnly=true): array {
    global $database;

    $bechdelResults = null;

    $result = ['ok' => true];

    // Get questionnaire info
    $sql = <<<SQL
SELECT
  q.title,
  q.country_id,
  c.name AS country,
  q.type
FROM questionnaires q
LEFT JOIN countries c ON q.country_id = c.id
WHERE q.id=:questionnaire_id;
SQL;

    $params = [':questionnaire_id' => $filter['questionnaireId']];
    $questionnaire = $database->fetch($sql, $params);

    $result['questionnaire'] = $questionnaire;

    $SQLJoin = '';
    $SQLWhereAnd = ' ';
    $joinPersonsTable = false;

    addHealthWhereSql($SQLWhereAnd, $joinPersonsTable, $filter);

    if (isset($filter['persons']) && (count($filter['persons'])) > 0) $joinPersonsTable = true;

    if (! empty($filter['country']) and ($filter['country'] !== 'UN')){
      addSQLWhere($SQLWhereAnd, 'c.countryid="' . $filter['country'] . '"');
    }

    if (! empty($filter['timeSpan'])) {
      $timeSpan = match ($filter['timeSpan']) {
        '1year' => '1 year',
        '2year' => '2 year',
        '3year' => '3 year',
        '5year' => '5 year',
        '10year' => '10 year',
        default => ''
      };
      if (! empty($timeSpan)) addSQLWhere($SQLWhereAnd, "c.date > (curdate() - interval $timeSpan)");
    }

    if (isset($filter['child']) && ($filter['child'] === 1)){
      $joinPersonsTable = true;
      addSQLWhere($SQLWhereAnd, "cp.child=1 ");
    }

    if (isset($filter['noUnilateral']) && ($filter['noUnilateral'] === 1)){
      addSQLWhere($SQLWhereAnd, " c.unilateral !=1 ");
    }

    if ($joinPersonsTable) $SQLJoin .= ' JOIN crashpersons cp on c.id = cp.crashid ';

    addPersonsWhereSql($SQLWhereAnd, $SQLJoin, $filter['persons']);

    // Get questionnaire answers
    if ($questionnaire['type'] === QuestionnaireType::standard->value) {
      $sql = <<<SQL
SELECT
  a.questionid AS id,
  q.text,
  a.answer,
  count(a.answer) AS aantal
FROM answers a
  LEFT JOIN articles ar                ON ar.id = a.articleid
  LEFT JOIN crashes c                  ON ar.crashid = c.id
  LEFT JOIN questionnaire_questions qq ON qq.question_id = a.questionid
  LEFT JOIN questions q                ON a.questionid = q.id
  $SQLJoin
WHERE qq.questionnaire_id=:questionnaire_id
  $SQLWhereAnd
GROUP BY qq.question_order, answer
ORDER BY qq.question_order
SQL;

      $params = [':questionnaire_id' => $filter['questionnaireId']];
      $dbQuestions = $database->fetchAllGroup($sql, $params);

      $questions = [];
      foreach ($dbQuestions as $questionId => $dbQuestion) {
        $questions[] = [
          'question_id'      => $questionId,
          'question'         => $dbQuestion[0]['text'],
          'no'               => $dbQuestion[0]['aantal'] ?? 0,
          'yes'              => $dbQuestion[1]['aantal'] ?? 0,
          'not_determinable' => $dbQuestion[2]['aantal'] ?? 0,
        ];
      }
      $result['questions'] = $questions;

    } else {
      // Bechdel type

      // Get questionnaire questions
      $sql = <<<SQL
SELECT
  q.id,
  q.text
FROM questionnaire_questions qq
LEFT JOIN questions q ON q.id = qq.question_id
WHERE qq.questionnaire_id=:questionnaire_id
ORDER BY qq.question_order
SQL;
      $questionnaire['questions'] = $database->fetchAll($sql, $params);

      function getInitBechdelResults($questions) {
        $results = [
          'yes'                    => 0,
          'no'                     => 0,
          'not_determinable'       => 0,
          'total_articles'         => 0,
          'total_questions_passed' => [],
        ];

        for ($i=0; $i<=count($questions); $i++) {
          $results['total_questions_passed'][$i] = 0;
        };

        return $results;
      }

      $sql = <<<SQL
SELECT
  ar.crashid,
  ar.id,
  ar.publishedtime,
  ar.title,
  ar.url,
  ar.sitename,
  c.countryid,
  c.date                                                AS crash_date,
  c.unilateral                                          AS crash_unilateral,
  c.countryid                                           AS crash_countryid,
  YEAR(ar.publishedtime)                                AS article_year,
  EXTRACT(YEAR_MONTH FROM ar.publishedtime)             AS article_year_month,
  GROUP_CONCAT(a.questionid ORDER BY qq.question_order) AS question_ids,
  GROUP_CONCAT(a.answer     ORDER BY qq.question_order) AS answers
FROM answers a
  LEFT JOIN articles ar                ON ar.id = a.articleid
  LEFT JOIN crashes c                  ON ar.crashid = c.id
  LEFT JOIN questionnaire_questions qq ON qq.question_id = a.questionid
  $SQLJoin
WHERE a.questionid in (SELECT question_id FROM questionnaire_questions WHERE questionnaire_id=:questionnaire_id)
  $SQLWhereAnd
GROUP BY a.articleid
ORDER BY ar.publishedtime;
SQL;

      $params = [
        ':questionnaire_id' => $filter['questionnaireId'],
      ];

      $articles = [];
      $crashes = [];

      $statement = $database->prepare($sql);
      $statement->execute($params);
      while ($article = $statement->fetch(PDO::FETCH_ASSOC)) {
        $article['publishedtime'] = datetimeDBToISO8601($article['publishedtime']);

        // Format and clean up article questions and answers data
        $articleQuestionIds = explode(',', $article['question_ids']);
        $articleAnswers = explode(',', $article['answers']);

        $article['questions'] = [];
        foreach ($questionnaire['questions'] as $question) {
          $index  = array_search($question['id'], $articleQuestionIds);
          $answer = $index === false? null : (int)$articleAnswers[$index];
          $article['questions'][$question['id']] = $answer;
        }

        unset($article['question_ids']);
        unset($article['answers']);

        $articleBechdel = self::getBechdelResult($article['questions']);
        $articleBechdel['total_questions'] = count($article['questions']);

        // Get group where the article belongs to
        switch ($group) {
          case 'year': {
            $bechdelResultsGroup = &$bechdelResults[$article['article_year']];
            break;
          }

          case 'month': {
            $bechdelResultsGroup = &$bechdelResults[$article['article_year_month']];
            break;
          }

          case 'source': {
            $bechdelResultsGroup = &$bechdelResults[$article['sitename']];
            break;
          }

          case 'country': {
            $bechdelResultsGroup = &$bechdelResults[$article['crash_countryid']];
            break;
          }

          default: $bechdelResultsGroup = &$bechdelResults;
        }

        // Initialize every to zero if first article in group
        if (! isset($bechdelResultsGroup)) $bechdelResultsGroup = getInitBechdelResults($questionnaire['questions']);

        if ($articleBechdel['result'] !== null) {
          switch ($articleBechdel['result']) {

            case Answer::no: {
              $bechdelResultsGroup['no'] += 1;
              $bechdelResultsGroup['total_articles'] += 1;
              $bechdelResultsGroup['total_questions_passed'][$articleBechdel['total_questions_passed']] += 1;
              break;
            }

            case Answer::yes: {
              $bechdelResultsGroup['yes'] += 1;
              $bechdelResultsGroup['total_articles'] += 1;
              $bechdelResultsGroup['total_questions_passed'][$articleBechdel['total_questions_passed']] += 1;
              break;
            }

            case Answer::notDeterminable: {
              $bechdelResultsGroup['not_determinable'] += 1;
              break;
            }

            default: throw new Exception('Internal error: Unknown Bechdel result');
          }

          if ($articleFilter['getArticles']) {
            $article['bechdelResult'] = $articleBechdel;

            if (self::passesArticleFilter($article, $articleFilter)) {
              $articles[] = $article;
              $crashes[] = [
                'id'         => $article['crashid'],
                'date'       => $article['crash_date'],
                'countryid'  => $article['crash_countryid'],
                'unilateral' => $article['crash_unilateral'] === 1,
              ];
            }
          }
        }

      }

      if ($group === 'year') {
        $resultsArray = [];
        foreach ($bechdelResults as $year => $bechdelResult) {
          $bechdelResult['year'] = $year;
          $resultsArray[] = $bechdelResult;
        }
        $result['bechdelResults'] = $resultsArray;
      } else if ($group === 'month') {
        $resultsArray = [];
        foreach ($bechdelResults as $yearMonth => $bechdelResult) {
          $bechdelResult['yearmonth'] = (string)$yearMonth;
          $resultsArray[] = $bechdelResult;
        }
        $result['bechdelResults'] = $resultsArray;
      } else if ($group === 'source') {
        $resultsArray = [];
        foreach ($bechdelResults as $source => $bechdelResult) {
          $bechdelResult['sitename'] = $source;
          $resultsArray[] = $bechdelResult;
        }
        $result['bechdelResults'] = $resultsArray;
      } else if ($group === 'country') {
        $resultsArray = [];
        foreach ($bechdelResults as $countryId => $bechdelResult) {
          $bechdelResult['countryid'] = $countryId;
          $resultsArray[] = $bechdelResult;
        }
        $result['bechdelResults'] = $resultsArray;
      } else $result['bechdelResults'] = $bechdelResults;
    }

    if (! empty($filter['minArticles'])) {
      $filtered = [];
      foreach ($result['bechdelResults'] as $row) {
        if ($row['total_articles'] >= $filter['minArticles']) {
          $filtered[] = $row;
        }
      }

      $result['bechdelResults'] = $filtered;
    }

    if ($articleFilter['getArticles']) {
      $result = [
        'ok' => true,
        'crashes' => $crashes,
        'articles' => array_slice($articles, $articleFilter['offset'], 1000),
      ];
    } else $result['questionnaire'] = $questionnaire;

    return $result;
  }
}

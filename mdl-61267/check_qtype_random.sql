\timing ON
\set echo all

\echo "Count of random questions"
SELECT COUNT(1) FROM mdl_question WHERE qtype = 'random';

\echo "Count of random questions in question_attempts"
SELECT COUNT(*)
FROM mdl_question_attempts qa
JOIN mdl_question q ON q.id = qa.questionid
WHERE q.qtype = 'random';

\echo "Count of random questions in question_usages"
SELECT COUNT(*)
FROM mdl_question_references qr
JOIN mdl_question_versions qv ON qv.questionbankentryid = qr.questionbankentryid
JOIN mdl_question q ON q.id = qv.questionid
WHERE q.qtype = 'random';

\echo "Checking whether entries exist with both random and non-random questions"
SELECT qv.questionbankentryid
FROM mdl_question_versions qv
JOIN mdl_question q ON q.id = qv.questionid
WHERE q.qtype = 'random'
INTERSECT
SELECT qv.questionbankentryid
FROM mdl_question_versions qv
JOIN mdl_question q ON q.id = qv.questionid
WHERE q.qtype != 'random';

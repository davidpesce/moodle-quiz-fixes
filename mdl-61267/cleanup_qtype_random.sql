\timing ON
\set echo all

\echo "Deleting question bank entries with random questions"
DELETE FROM mdl_question_bank_entries
WHERE id IN (
    SELECT qv.questionbankentryid
    FROM mdl_question_versions qv
    JOIN mdl_question q ON q.id = qv.questionid
    WHERE q.qtype = 'random'
);

\echo "Deleting question versions with random questions"
DELETE FROM mdl_question_versions qv
WHERE questionid IN (
    SELECT id FROM mdl_question q WHERE q.qtype = 'random'
);

\echo "Deleting random questions"
DELETE FROM mdl_question WHERE qtype = 'random';

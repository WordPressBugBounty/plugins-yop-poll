<?php
/**
 * The shapes of the JSON blobs stored in the `meta_data` columns.
 *
 * @package YopPoll
 */

namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Type aliases for decoded `meta_data`. Declares no runtime API.
 *
 * Polls, elements and subelements each keep their settings as one JSON object
 * in `meta_data`. Nothing in PHP describes those objects -- `decode_meta()`
 * hands back a bare `array`, so every reader has been on its own to remember
 * which keys exist and which of them a given row actually carries.
 *
 * These aliases write that down once. They are documentation that PHPStan
 * enforces: import one with `@phpstan-import-type` and annotate the parameter
 * that receives decoded meta, and an unguarded read of a key that may not be
 * there becomes an analysis error instead of a notice on someone's site.
 *
 * ## Everything is optional, and that is not laziness
 *
 * Every key below is marked `?`. That is a statement about the data, not a
 * hedge. `Migrator::map_poll_meta()` and `Migrator::migrate_elements()` pass a
 * v6 row through with its values rewritten and nothing backfilled -- a
 * migrated poll carries exactly the keys the v6 builder wrote, and v6 wrote
 * fewer of them. `map_poll_meta()` even *removes* one (`style.questions
 * .textColor`, when it would be invisible against the background). On top of
 * that `decode_meta()` returns `array()` for an empty or unparseable column,
 * so `options` and `style` themselves can be absent.
 *
 * A required key here would therefore be a lie that reads as a guarantee. The
 * read-side default is the contract; see the "Adding a `meta_data` key without
 * a read-side default" pitfall in CLAUDE.md.
 *
 * ## Shapes are unsealed
 *
 * PHPStan lets an array with extra keys satisfy an array shape, which is what
 * makes these usable at all: a row may carry an older spelling (see
 * `Meta_Aliases`), a key an addon owns, or a v6 key nothing reads any more,
 * and none of that conflicts with the shape. Listing a key is what buys the
 * presence check; omitting one costs coverage, not correctness. So prefer
 * `Meta_Aliases::resolve()` -- which takes a plain array on purpose -- for
 * anything read through its older spellings.
 *
 * The multi-select settings (`votePermissions`, `blockVoters`,
 * `showResultsMoment`, `showResultsTo`, `resultsDetails`) are `list<string>|
 * string` because v6 stored a single choice as a bare string, and the readers
 * still normalise for it -- `REST_Base::is_vote_allowed()`, `REST_Polls` and
 * `Poll_Results::shows()` each coerce a scalar to a one-element array on the
 * way in. Declaring them `list<string>` would type away the case those
 * branches exist to handle.
 *
 * Value types are deliberately loose where the data genuinely varies. The
 * builder writes `'10'` where v6 wrote `'10px'` and the migrator casts what it
 * recognises to `int`, so numeric settings are `int|string` rather than a
 * precision that would not survive contact with a real row.
 *
 * @phpstan-type PollStyleSection array{
 *     backgroundColor?: string,
 *     backgroundImage?: array{url?: string, id?: int|string},
 *     backgroundPosition?: string,
 *     backgroundRepeat?: string,
 *     backgroundSize?: string,
 *     borderColor?: string,
 *     borderRadius?: int|string,
 *     borderSize?: int|string,
 *     borderStyle?: string,
 *     paddingLeftRight?: int|string,
 *     paddingTopBottom?: int|string,
 * }
 * @phpstan-type QuestionStyleSection array{
 *     labelGap?: int|string,
 *     paddingLeftRight?: int|string,
 *     paddingTopBottom?: int|string,
 *     textAlign?: string,
 *     textColor?: string,
 *     textSize?: int|string,
 *     textWeight?: string,
 * }
 * @phpstan-type AnswerStyleSection array{
 *     answerGap?: int|string,
 *     checkedColor?: string,
 *     colorScheme?: string,
 *     markerGap?: int|string,
 *     markerSize?: int|string,
 *     paddingLeftRight?: int|string,
 *     paddingTopBottom?: int|string,
 *     textColor?: string,
 *     textSize?: int|string,
 *     textWeight?: string,
 * }
 * @phpstan-type ButtonStyleSection array{
 *     align?: string,
 *     backgroundColor?: string,
 *     borderColor?: string,
 *     borderRadius?: int|string,
 *     borderSize?: int|string,
 *     borderStyle?: string,
 *     paddingLeftRight?: int|string,
 *     paddingTopBottom?: int|string,
 *     textColor?: string,
 *     textSize?: int|string,
 *     textWeight?: string,
 * }
 * @phpstan-type ErrorStyleSection array{
 *     backgroundColor?: string,
 *     borderLeftColorForError?: string,
 *     borderLeftColorForSuccess?: string,
 *     borderLeftSize?: int|string,
 *     paddingLeftRight?: int|string,
 *     paddingTopBottom?: int|string,
 *     textColor?: string,
 *     textSize?: int|string,
 *     textWeight?: string,
 * }
 * @phpstan-type PollStyle array{
 *     answers?: AnswerStyleSection,
 *     buttons?: ButtonStyleSection,
 *     captcha?: array<string, mixed>,
 *     custom?: array{css?: string},
 *     errors?: ErrorStyleSection,
 *     poll?: PollStyleSection,
 *     questions?: QuestionStyleSection,
 * }
 * @phpstan-type PollGeneralOptions array{
 *     autoGeneratePollPage?: string,
 *     emailNotificationsFromEmail?: string,
 *     emailNotificationsFromName?: string,
 *     emailNotificationsMessage?: string,
 *     emailNotificationsRecipients?: string,
 *     emailNotificationsSubject?: string,
 *     emailResultsFromEmail?: string,
 *     emailResultsFromName?: string,
 *     emailResultsMessage?: string,
 *     emailResultsRecipients?: string,
 *     emailResultsSubject?: string,
 *     enableGdpr?: string,
 *     endDateCountdownText?: string,
 *     endDateCustom?: string,
 *     endDateDisplay?: string,
 *     endDateOption?: string,
 *     endDateText?: string,
 *     gdprConsentText?: string,
 *     gdprSolution?: string,
 *     notificationMessageLocation?: string,
 *     pageId?: int|string,
 *     resetPollStatsAutomatically?: string,
 *     resetPollStatsEvery?: int|string,
 *     resetPollStatsEveryPeriod?: string,
 *     resetPollStatsOn?: string,
 *     resultsLabelText?: string,
 *     sendEmailNotifications?: string,
 *     showResultsLink?: string,
 *     showTotalAnswers?: string,
 *     showTotalVotes?: string,
 *     startDateCustom?: string,
 *     startDateOption?: string,
 *     status?: string,
 *     useCaptcha?: string,
 *     voteButtonLabel?: string,
 * }
 * @phpstan-type PollAccessOptions array{
 *     allowChangeVote?: string,
 *     anonymousVotingEnabled?: string,
 *     blockForPeriod?: string,
 *     blockForValue?: int|string,
 *     blockLengthType?: string,
 *     blockVoters?: list<string>|string,
 *     enableAnonymousVoting?: string,
 *     enableCustomVotingWeight?: string,
 *     limitVotesPerUser?: string,
 *     votePermissions?: list<string>|string,
 *     votesPerUserAllowed?: int|string,
 *     votingWeights?: array<string, mixed>,
 * }
 * @phpstan-type PollResultsOptions array{
 *     backToVoteCaption?: string,
 *     backToVoteOption?: string,
 *     customDateResults?: string,
 *     displayResultsAs?: string,
 *     percentagesDecimals?: int|string,
 *     resultsDetails?: list<string>|string,
 *     roundPercentage?: string,
 *     showResultsMoment?: list<string>|string,
 *     showResultsTo?: list<string>|string,
 *     sortResults?: string,
 *     sortResultsRule?: string,
 * }
 * @phpstan-type PollMeta array{
 *     options?: array{
 *         access?: PollAccessOptions,
 *         poll?: PollGeneralOptions,
 *         results?: PollResultsOptions,
 *     },
 *     style?: PollStyle,
 * }
 * @phpstan-type ElementMeta array{
 *     addOtherAnswers?: string,
 *     align?: string,
 *     allowMultipleAnswers?: string,
 *     allowOtherAnswers?: string,
 *     answersAlign?: string,
 *     answersColumns?: int|string,
 *     answersDisplay?: string,
 *     answersSort?: string,
 *     displayOtherAnswersInResults?: string,
 *     instantVote?: string,
 *     makeRequired?: string,
 *     markStyle?: string,
 *     maxCharsAllowed?: int|string,
 *     multipleAnswersMaxim?: int|string,
 *     multipleAnswersMinim?: int|string,
 *     otherAnswersCount?: int|string,
 *     otherAnswersLabel?: string,
 *     otherAnswersType?: string,
 *     otherFieldAlwaysVisible?: string,
 *     otherFieldSize?: string,
 *     otherFieldType?: string,
 *     otherMaxChars?: int|string,
 *     otherMaxCharsAllowed?: int|string,
 *     required?: string,
 *     resultsColorForOtherAnswers?: string,
 *     size?: string,
 * }
 * @phpstan-type SubelementMeta array{
 *     link?: string,
 *     makeDefault?: string,
 *     makeLink?: string,
 *     resultsColor?: string,
 * }
 */
final class Meta_Types {

	/**
	 * Not instantiable: this class exists for its type aliases alone.
	 */
	private function __construct() {
	}
}

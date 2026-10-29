import Model from 'flarum/common/Model';

/**
 * The `moments` type as the notification list sees it: just enough to word a
 * notification and link to it. Moments themselves are read through Moments'
 * own API, not the store.
 */
export default class Moment extends Model {
  excerpt = Model.attribute<string>('excerpt');
  rootId = Model.attribute<number>('rootId');
  isReply = Model.attribute<boolean>('isReply');
}

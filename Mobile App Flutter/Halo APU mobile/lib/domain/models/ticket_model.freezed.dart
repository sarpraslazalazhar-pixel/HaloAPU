// GENERATED CODE - DO NOT MODIFY BY HAND
// coverage:ignore-file
// ignore_for_file: type=lint, type=warning, deprecated_member_use, deprecated_member_use_from_same_package
// ignore_for_file: unused_element, deprecated_member_use, deprecated_member_use_from_same_package, use_function_type_syntax_for_parameters, unnecessary_const, avoid_init_to_null, invalid_override_different_default_values_named, prefer_expression_function_bodies, annotate_overrides, invalid_annotation_target, unnecessary_question_mark

part of 'ticket_model.dart';

// **************************************************************************
// FreezedGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// dart format off
T _$identity<T>(T value) => value;

/// @nodoc
mixin _$TicketModel {

 String get id; String get title; String get description; String get category; TicketStatus get status; DateTime get createdAt; String get requesterName; String? get assignedTo; String? get attachmentUrl; List<TicketReply>? get logs; List<Map<String, dynamic>>? get operators; Map<String, dynamic>? get csat;
/// Create a copy of TicketModel
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$TicketModelCopyWith<TicketModel> get copyWith => _$TicketModelCopyWithImpl<TicketModel>(this as TicketModel, _$identity);

  /// Serializes this TicketModel to a JSON map.
  Map<String, dynamic> toJson();


@override
bool operator ==(Object other) {
  return identical(this, other) || (other.runtimeType == runtimeType&&other is TicketModel&&(identical(other.id, id) || other.id == id)&&(identical(other.title, title) || other.title == title)&&(identical(other.description, description) || other.description == description)&&(identical(other.category, category) || other.category == category)&&(identical(other.status, status) || other.status == status)&&(identical(other.createdAt, createdAt) || other.createdAt == createdAt)&&(identical(other.requesterName, requesterName) || other.requesterName == requesterName)&&(identical(other.assignedTo, assignedTo) || other.assignedTo == assignedTo)&&(identical(other.attachmentUrl, attachmentUrl) || other.attachmentUrl == attachmentUrl)&&const DeepCollectionEquality().equals(other.logs, logs)&&const DeepCollectionEquality().equals(other.operators, operators)&&const DeepCollectionEquality().equals(other.csat, csat));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode => Object.hash(runtimeType,id,title,description,category,status,createdAt,requesterName,assignedTo,attachmentUrl,const DeepCollectionEquality().hash(logs),const DeepCollectionEquality().hash(operators),const DeepCollectionEquality().hash(csat));

@override
String toString() {
  return 'TicketModel(id: $id, title: $title, description: $description, category: $category, status: $status, createdAt: $createdAt, requesterName: $requesterName, assignedTo: $assignedTo, attachmentUrl: $attachmentUrl, logs: $logs, operators: $operators, csat: $csat)';
}


}

/// @nodoc
abstract mixin class $TicketModelCopyWith<$Res>  {
  factory $TicketModelCopyWith(TicketModel value, $Res Function(TicketModel) _then) = _$TicketModelCopyWithImpl;
@useResult
$Res call({
 String id, String title, String description, String category, TicketStatus status, DateTime createdAt, String requesterName, String? assignedTo, String? attachmentUrl, List<TicketReply>? logs, List<Map<String, dynamic>>? operators, Map<String, dynamic>? csat
});




}
/// @nodoc
class _$TicketModelCopyWithImpl<$Res>
    implements $TicketModelCopyWith<$Res> {
  _$TicketModelCopyWithImpl(this._self, this._then);

  final TicketModel _self;
  final $Res Function(TicketModel) _then;

/// Create a copy of TicketModel
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? title = null,Object? description = null,Object? category = null,Object? status = null,Object? createdAt = null,Object? requesterName = null,Object? assignedTo = freezed,Object? attachmentUrl = freezed,Object? logs = freezed,Object? operators = freezed,Object? csat = freezed,}) {
  return _then(TicketModel(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as String,title: null == title ? _self.title : title // ignore: cast_nullable_to_non_nullable
as String,description: null == description ? _self.description : description // ignore: cast_nullable_to_non_nullable
as String,category: null == category ? _self.category : category // ignore: cast_nullable_to_non_nullable
as String,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as TicketStatus,createdAt: null == createdAt ? _self.createdAt : createdAt // ignore: cast_nullable_to_non_nullable
as DateTime,requesterName: null == requesterName ? _self.requesterName : requesterName // ignore: cast_nullable_to_non_nullable
as String,assignedTo: freezed == assignedTo ? _self.assignedTo : assignedTo // ignore: cast_nullable_to_non_nullable
as String?,attachmentUrl: freezed == attachmentUrl ? _self.attachmentUrl : attachmentUrl // ignore: cast_nullable_to_non_nullable
as String?,logs: freezed == logs ? _self.logs : logs // ignore: cast_nullable_to_non_nullable
as List<TicketReply>?,operators: freezed == operators ? _self.operators : operators // ignore: cast_nullable_to_non_nullable
as List<Map<String, dynamic>>?,csat: freezed == csat ? _self.csat : csat // ignore: cast_nullable_to_non_nullable
as Map<String, dynamic>?,
  ));
}

}


/// Adds pattern-matching-related methods to [TicketModel].
extension TicketModelPatterns on TicketModel {
/// A variant of `map` that fallback to returning `orElse`.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case _:
///     return orElse();
/// }
/// ```

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _TicketModel value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _TicketModel() when $default != null:
return $default(_that);case _:
  return orElse();

}
}
/// A `switch`-like method, using callbacks.
///
/// Callbacks receives the raw object, upcasted.
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case final Subclass2 value:
///     return ...;
/// }
/// ```

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _TicketModel value)  $default,){
final _that = this;
switch (_that) {
case _TicketModel():
return $default(_that);}
}
/// A variant of `map` that fallback to returning `null`.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case _:
///     return null;
/// }
/// ```

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _TicketModel value)?  $default,){
final _that = this;
switch (_that) {
case _TicketModel() when $default != null:
return $default(_that);case _:
  return null;

}
}
/// A variant of `when` that fallback to an `orElse` callback.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case _:
///     return orElse();
/// }
/// ```

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( String id,  String title,  String description,  String category,  TicketStatus status,  DateTime createdAt,  String requesterName,  String? assignedTo,  String? attachmentUrl,  List<TicketReply>? logs,  List<Map<String, dynamic>>? operators,  Map<String, dynamic>? csat)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _TicketModel() when $default != null:
return $default(_that.id,_that.title,_that.description,_that.category,_that.status,_that.createdAt,_that.requesterName,_that.assignedTo,_that.attachmentUrl,_that.logs,_that.operators,_that.csat);case _:
  return orElse();

}
}
/// A `switch`-like method, using callbacks.
///
/// As opposed to `map`, this offers destructuring.
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case Subclass2(:final field2):
///     return ...;
/// }
/// ```

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( String id,  String title,  String description,  String category,  TicketStatus status,  DateTime createdAt,  String requesterName,  String? assignedTo,  String? attachmentUrl,  List<TicketReply>? logs,  List<Map<String, dynamic>>? operators,  Map<String, dynamic>? csat)  $default,) {final _that = this;
switch (_that) {
case _TicketModel():
return $default(_that.id,_that.title,_that.description,_that.category,_that.status,_that.createdAt,_that.requesterName,_that.assignedTo,_that.attachmentUrl,_that.logs,_that.operators,_that.csat);}
}
/// A variant of `when` that fallback to returning `null`
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case _:
///     return null;
/// }
/// ```

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( String id,  String title,  String description,  String category,  TicketStatus status,  DateTime createdAt,  String requesterName,  String? assignedTo,  String? attachmentUrl,  List<TicketReply>? logs,  List<Map<String, dynamic>>? operators,  Map<String, dynamic>? csat)?  $default,) {final _that = this;
switch (_that) {
case _TicketModel() when $default != null:
return $default(_that.id,_that.title,_that.description,_that.category,_that.status,_that.createdAt,_that.requesterName,_that.assignedTo,_that.attachmentUrl,_that.logs,_that.operators,_that.csat);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable()

class _TicketModel implements TicketModel {
  const _TicketModel({required this.id, required this.title, required this.description, required this.category, required this.status, required this.createdAt, required this.requesterName, this.assignedTo, this.attachmentUrl,  List<TicketReply>? logs,  List<Map<String, dynamic>>? operators,  Map<String, dynamic>? csat}): _logs = logs,_operators = operators,_csat = csat;
  factory _TicketModel.fromJson(Map<String, dynamic> json) => _$TicketModelFromJson(json);

@override final  String id;
@override final  String title;
@override final  String description;
@override final  String category;
@override final  TicketStatus status;
@override final  DateTime createdAt;
@override final  String requesterName;
@override final  String? assignedTo;
@override final  String? attachmentUrl;
 final  List<TicketReply>? _logs;
@override List<TicketReply>? get logs {
  final value = _logs;
  if (value == null) return null;
  if (_logs is EqualUnmodifiableListView) return _logs;
  // ignore: implicit_dynamic_type
  return EqualUnmodifiableListView(value);
}

 final  List<Map<String, dynamic>>? _operators;
@override List<Map<String, dynamic>>? get operators {
  final value = _operators;
  if (value == null) return null;
  if (_operators is EqualUnmodifiableListView) return _operators;
  // ignore: implicit_dynamic_type
  return EqualUnmodifiableListView(value);
}

 final  Map<String, dynamic>? _csat;
@override Map<String, dynamic>? get csat {
  final value = _csat;
  if (value == null) return null;
  if (_csat is EqualUnmodifiableMapView) return _csat;
  // ignore: implicit_dynamic_type
  return EqualUnmodifiableMapView(value);
}


/// Create a copy of TicketModel
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$TicketModelCopyWith<_TicketModel> get copyWith => __$TicketModelCopyWithImpl<_TicketModel>(this, _$identity);

@override
Map<String, dynamic> toJson() {
  return _$TicketModelToJson(this, );
}

@override
bool operator ==(Object other) {
  return identical(this, other) || (other.runtimeType == runtimeType&&other is _TicketModel&&(identical(other.id, id) || other.id == id)&&(identical(other.title, title) || other.title == title)&&(identical(other.description, description) || other.description == description)&&(identical(other.category, category) || other.category == category)&&(identical(other.status, status) || other.status == status)&&(identical(other.createdAt, createdAt) || other.createdAt == createdAt)&&(identical(other.requesterName, requesterName) || other.requesterName == requesterName)&&(identical(other.assignedTo, assignedTo) || other.assignedTo == assignedTo)&&(identical(other.attachmentUrl, attachmentUrl) || other.attachmentUrl == attachmentUrl)&&const DeepCollectionEquality().equals(other._logs, _logs)&&const DeepCollectionEquality().equals(other._operators, _operators)&&const DeepCollectionEquality().equals(other._csat, _csat));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode => Object.hash(runtimeType,id,title,description,category,status,createdAt,requesterName,assignedTo,attachmentUrl,const DeepCollectionEquality().hash(_logs),const DeepCollectionEquality().hash(_operators),const DeepCollectionEquality().hash(_csat));

@override
String toString() {
  return 'TicketModel(id: $id, title: $title, description: $description, category: $category, status: $status, createdAt: $createdAt, requesterName: $requesterName, assignedTo: $assignedTo, attachmentUrl: $attachmentUrl, logs: $logs, operators: $operators, csat: $csat)';
}


}

/// @nodoc
abstract mixin class _$TicketModelCopyWith<$Res> implements $TicketModelCopyWith<$Res> {
  factory _$TicketModelCopyWith(_TicketModel value, $Res Function(_TicketModel) _then) = __$TicketModelCopyWithImpl;
@override @useResult
$Res call({
 String id, String title, String description, String category, TicketStatus status, DateTime createdAt, String requesterName, String? assignedTo, String? attachmentUrl, List<TicketReply>? logs, List<Map<String, dynamic>>? operators, Map<String, dynamic>? csat
});




}
/// @nodoc
class __$TicketModelCopyWithImpl<$Res>
    implements _$TicketModelCopyWith<$Res> {
  __$TicketModelCopyWithImpl(this._self, this._then);

  final _TicketModel _self;
  final $Res Function(_TicketModel) _then;

/// Create a copy of TicketModel
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? title = null,Object? description = null,Object? category = null,Object? status = null,Object? createdAt = null,Object? requesterName = null,Object? assignedTo = freezed,Object? attachmentUrl = freezed,Object? logs = freezed,Object? operators = freezed,Object? csat = freezed,}) {
  return _then(_TicketModel(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as String,title: null == title ? _self.title : title // ignore: cast_nullable_to_non_nullable
as String,description: null == description ? _self.description : description // ignore: cast_nullable_to_non_nullable
as String,category: null == category ? _self.category : category // ignore: cast_nullable_to_non_nullable
as String,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as TicketStatus,createdAt: null == createdAt ? _self.createdAt : createdAt // ignore: cast_nullable_to_non_nullable
as DateTime,requesterName: null == requesterName ? _self.requesterName : requesterName // ignore: cast_nullable_to_non_nullable
as String,assignedTo: freezed == assignedTo ? _self.assignedTo : assignedTo // ignore: cast_nullable_to_non_nullable
as String?,attachmentUrl: freezed == attachmentUrl ? _self.attachmentUrl : attachmentUrl // ignore: cast_nullable_to_non_nullable
as String?,logs: freezed == logs ? _self._logs : logs // ignore: cast_nullable_to_non_nullable
as List<TicketReply>?,operators: freezed == operators ? _self._operators : operators // ignore: cast_nullable_to_non_nullable
as List<Map<String, dynamic>>?,csat: freezed == csat ? _self._csat : csat // ignore: cast_nullable_to_non_nullable
as Map<String, dynamic>?,
  ));
}


}


/// @nodoc
mixin _$TicketReply {

 int get id; String get action; String get note; DateTime get createdAt; String? get adminName; bool get isFromAdmin; List<Map<String, dynamic>>? get attachments;
/// Create a copy of TicketReply
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$TicketReplyCopyWith<TicketReply> get copyWith => _$TicketReplyCopyWithImpl<TicketReply>(this as TicketReply, _$identity);

  /// Serializes this TicketReply to a JSON map.
  Map<String, dynamic> toJson();


@override
bool operator ==(Object other) {
  return identical(this, other) || (other.runtimeType == runtimeType&&other is TicketReply&&(identical(other.id, id) || other.id == id)&&(identical(other.action, action) || other.action == action)&&(identical(other.note, note) || other.note == note)&&(identical(other.createdAt, createdAt) || other.createdAt == createdAt)&&(identical(other.adminName, adminName) || other.adminName == adminName)&&(identical(other.isFromAdmin, isFromAdmin) || other.isFromAdmin == isFromAdmin)&&const DeepCollectionEquality().equals(other.attachments, attachments));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode => Object.hash(runtimeType,id,action,note,createdAt,adminName,isFromAdmin,const DeepCollectionEquality().hash(attachments));

@override
String toString() {
  return 'TicketReply(id: $id, action: $action, note: $note, createdAt: $createdAt, adminName: $adminName, isFromAdmin: $isFromAdmin, attachments: $attachments)';
}


}

/// @nodoc
abstract mixin class $TicketReplyCopyWith<$Res>  {
  factory $TicketReplyCopyWith(TicketReply value, $Res Function(TicketReply) _then) = _$TicketReplyCopyWithImpl;
@useResult
$Res call({
 int id, String action, String note, DateTime createdAt, String? adminName, bool isFromAdmin, List<Map<String, dynamic>>? attachments
});




}
/// @nodoc
class _$TicketReplyCopyWithImpl<$Res>
    implements $TicketReplyCopyWith<$Res> {
  _$TicketReplyCopyWithImpl(this._self, this._then);

  final TicketReply _self;
  final $Res Function(TicketReply) _then;

/// Create a copy of TicketReply
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? action = null,Object? note = null,Object? createdAt = null,Object? adminName = freezed,Object? isFromAdmin = null,Object? attachments = freezed,}) {
  return _then(TicketReply(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,action: null == action ? _self.action : action // ignore: cast_nullable_to_non_nullable
as String,note: null == note ? _self.note : note // ignore: cast_nullable_to_non_nullable
as String,createdAt: null == createdAt ? _self.createdAt : createdAt // ignore: cast_nullable_to_non_nullable
as DateTime,adminName: freezed == adminName ? _self.adminName : adminName // ignore: cast_nullable_to_non_nullable
as String?,isFromAdmin: null == isFromAdmin ? _self.isFromAdmin : isFromAdmin // ignore: cast_nullable_to_non_nullable
as bool,attachments: freezed == attachments ? _self.attachments : attachments // ignore: cast_nullable_to_non_nullable
as List<Map<String, dynamic>>?,
  ));
}

}


/// Adds pattern-matching-related methods to [TicketReply].
extension TicketReplyPatterns on TicketReply {
/// A variant of `map` that fallback to returning `orElse`.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case _:
///     return orElse();
/// }
/// ```

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _TicketReply value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _TicketReply() when $default != null:
return $default(_that);case _:
  return orElse();

}
}
/// A `switch`-like method, using callbacks.
///
/// Callbacks receives the raw object, upcasted.
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case final Subclass2 value:
///     return ...;
/// }
/// ```

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _TicketReply value)  $default,){
final _that = this;
switch (_that) {
case _TicketReply():
return $default(_that);}
}
/// A variant of `map` that fallback to returning `null`.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case _:
///     return null;
/// }
/// ```

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _TicketReply value)?  $default,){
final _that = this;
switch (_that) {
case _TicketReply() when $default != null:
return $default(_that);case _:
  return null;

}
}
/// A variant of `when` that fallback to an `orElse` callback.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case _:
///     return orElse();
/// }
/// ```

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  String action,  String note,  DateTime createdAt,  String? adminName,  bool isFromAdmin,  List<Map<String, dynamic>>? attachments)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _TicketReply() when $default != null:
return $default(_that.id,_that.action,_that.note,_that.createdAt,_that.adminName,_that.isFromAdmin,_that.attachments);case _:
  return orElse();

}
}
/// A `switch`-like method, using callbacks.
///
/// As opposed to `map`, this offers destructuring.
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case Subclass2(:final field2):
///     return ...;
/// }
/// ```

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  String action,  String note,  DateTime createdAt,  String? adminName,  bool isFromAdmin,  List<Map<String, dynamic>>? attachments)  $default,) {final _that = this;
switch (_that) {
case _TicketReply():
return $default(_that.id,_that.action,_that.note,_that.createdAt,_that.adminName,_that.isFromAdmin,_that.attachments);}
}
/// A variant of `when` that fallback to returning `null`
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case _:
///     return null;
/// }
/// ```

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  String action,  String note,  DateTime createdAt,  String? adminName,  bool isFromAdmin,  List<Map<String, dynamic>>? attachments)?  $default,) {final _that = this;
switch (_that) {
case _TicketReply() when $default != null:
return $default(_that.id,_that.action,_that.note,_that.createdAt,_that.adminName,_that.isFromAdmin,_that.attachments);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable()

class _TicketReply implements TicketReply {
  const _TicketReply({required this.id, required this.action, required this.note, required this.createdAt, this.adminName, required this.isFromAdmin,  List<Map<String, dynamic>>? attachments}): _attachments = attachments;
  factory _TicketReply.fromJson(Map<String, dynamic> json) => _$TicketReplyFromJson(json);

@override final  int id;
@override final  String action;
@override final  String note;
@override final  DateTime createdAt;
@override final  String? adminName;
@override final  bool isFromAdmin;
 final  List<Map<String, dynamic>>? _attachments;
@override List<Map<String, dynamic>>? get attachments {
  final value = _attachments;
  if (value == null) return null;
  if (_attachments is EqualUnmodifiableListView) return _attachments;
  // ignore: implicit_dynamic_type
  return EqualUnmodifiableListView(value);
}


/// Create a copy of TicketReply
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$TicketReplyCopyWith<_TicketReply> get copyWith => __$TicketReplyCopyWithImpl<_TicketReply>(this, _$identity);

@override
Map<String, dynamic> toJson() {
  return _$TicketReplyToJson(this, );
}

@override
bool operator ==(Object other) {
  return identical(this, other) || (other.runtimeType == runtimeType&&other is _TicketReply&&(identical(other.id, id) || other.id == id)&&(identical(other.action, action) || other.action == action)&&(identical(other.note, note) || other.note == note)&&(identical(other.createdAt, createdAt) || other.createdAt == createdAt)&&(identical(other.adminName, adminName) || other.adminName == adminName)&&(identical(other.isFromAdmin, isFromAdmin) || other.isFromAdmin == isFromAdmin)&&const DeepCollectionEquality().equals(other._attachments, _attachments));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode => Object.hash(runtimeType,id,action,note,createdAt,adminName,isFromAdmin,const DeepCollectionEquality().hash(_attachments));

@override
String toString() {
  return 'TicketReply(id: $id, action: $action, note: $note, createdAt: $createdAt, adminName: $adminName, isFromAdmin: $isFromAdmin, attachments: $attachments)';
}


}

/// @nodoc
abstract mixin class _$TicketReplyCopyWith<$Res> implements $TicketReplyCopyWith<$Res> {
  factory _$TicketReplyCopyWith(_TicketReply value, $Res Function(_TicketReply) _then) = __$TicketReplyCopyWithImpl;
@override @useResult
$Res call({
 int id, String action, String note, DateTime createdAt, String? adminName, bool isFromAdmin, List<Map<String, dynamic>>? attachments
});




}
/// @nodoc
class __$TicketReplyCopyWithImpl<$Res>
    implements _$TicketReplyCopyWith<$Res> {
  __$TicketReplyCopyWithImpl(this._self, this._then);

  final _TicketReply _self;
  final $Res Function(_TicketReply) _then;

/// Create a copy of TicketReply
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? action = null,Object? note = null,Object? createdAt = null,Object? adminName = freezed,Object? isFromAdmin = null,Object? attachments = freezed,}) {
  return _then(_TicketReply(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,action: null == action ? _self.action : action // ignore: cast_nullable_to_non_nullable
as String,note: null == note ? _self.note : note // ignore: cast_nullable_to_non_nullable
as String,createdAt: null == createdAt ? _self.createdAt : createdAt // ignore: cast_nullable_to_non_nullable
as DateTime,adminName: freezed == adminName ? _self.adminName : adminName // ignore: cast_nullable_to_non_nullable
as String?,isFromAdmin: null == isFromAdmin ? _self.isFromAdmin : isFromAdmin // ignore: cast_nullable_to_non_nullable
as bool,attachments: freezed == attachments ? _self._attachments : attachments // ignore: cast_nullable_to_non_nullable
as List<Map<String, dynamic>>?,
  ));
}


}

// dart format on

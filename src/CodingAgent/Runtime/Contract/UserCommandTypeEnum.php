<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\AgentCore\Domain\Event\AgentCommandKindEnum;

enum UserCommandTypeEnum: string
{
    case Message = 'message';
    case Steer = AgentCommandKindEnum::Steer->value;
    case FollowUp = AgentCommandKindEnum::FollowUp->value;
    case AppendMessage = AgentCommandKindEnum::AppendMessage->value;
    case Cancel = AgentCommandKindEnum::Cancel->value;
    case AnswerHuman = 'answer_human';
    case ShellCommand = AgentCommandKindEnum::ShellCommand->value;
    case SelectHistoryTurn = 'select_history_turn';
    case BackgroundBash = 'background_bash';
    case BackgroundChild = 'background_child';
    case BackgroundCodeMode = 'background_code_mode';
    case StopCodeMode = 'stop_code_mode';
}
